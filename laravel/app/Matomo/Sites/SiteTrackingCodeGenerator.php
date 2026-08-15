<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Api\SitesManagerTrackingCodeRequest;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\Events\ImageTrackingCodeGenerating;
use App\Matomo\Sites\Events\JavascriptTrackingCodeGenerating;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class SiteTrackingCodeGenerator
{
    public function __construct(
        private SiteRepository $sites,
        private OptionRepository $options,
        private Dispatcher $events,
    ) {}

    public function javascript(SitesManagerTrackingCodeRequest $request, string $defaultMatomoUrl = ''): string
    {
        [$javascriptEndpoint, $phpEndpoint] = $this->endpoints($request->forceMatomoEndpoint);
        $host = $this->host($request->matomoUrl !== '' ? $request->matomoUrl : $defaultMatomoUrl);
        $options = '';

        if ($request->groupPageTitlesByDomain) {
            $options .= '  _paq.push(["setDocumentTitle", document.domain + "/" + document.title]);'."\n";
        }

        $mergeAliases = $request->mergeAliasUrls || $request->crossDomain;
        if ($request->mergeSubdomains || $mergeAliases) {
            $hosts = $this->siteHosts($request->siteId);
            if ($request->mergeSubdomains && $hosts !== []) {
                $options .= '  _paq.push(["setCookieDomain", '.json_encode('*.'.$hosts[0], JSON_UNESCAPED_SLASHES).']);'."\n";
            }

            if ($mergeAliases && $hosts !== []) {
                $domains = array_map(static fn (string $value): string => '*.'.$value, $hosts);
                $options .= '  _paq.push(["setDomains", '.json_encode($domains, JSON_UNESCAPED_SLASHES).']);'."\n";
            }
        }

        if ($request->crossDomain) {
            $options .= '  _paq.push(["enableCrossDomainLinking"]);'."\n";
        }

        $options .= $this->customVariables($request->visitorCustomVariables, 'visit', 'visitor');
        $options .= $this->customVariables($request->pageCustomVariables, 'page', 'action (page view, download, click, site search)');

        foreach ([
            [$request->disableCampaignParameters, '["disableCampaignParameters"]'],
            [$request->campaignNameParameter !== '', '["setCampaignNameKey", '.json_encode($request->campaignNameParameter).']'],
            [$request->campaignKeywordParameter !== '', '["setCampaignKeywordKey", '.json_encode($request->campaignKeywordParameter).']'],
            [$request->doNotTrack, '["setDoNotTrack", true]'],
            [$request->excludedQueryParameters !== [], '["setExcludedQueryParams", '.json_encode($request->excludedQueryParameters).']'],
            [$request->excludedReferrers !== [], '["setExcludedReferrers", '.json_encode($request->excludedReferrers).']'],
            [$request->disableCookies, '["disableCookies"]'],
        ] as [$enabled, $statement]) {
            if ($enabled) {
                $options .= '  _paq.push('.$statement.');'."\n";
            }
        }

        $code = [
            'idSite' => $request->siteId,
            'piwikUrl' => $host,
            'options' => $options,
            'optionsBeforeTrackerUrl' => '',
            'protocol' => '//',
            'loadAsync' => true,
            'trackNoScript' => $request->trackNoScript,
            'matomoJsFilename' => $javascriptEndpoint,
            'matomoPhpFilename' => $phpEndpoint,
        ];
        $event = new JavascriptTrackingCodeGenerating($code, get_object_vars($request));
        $this->events->dispatch($event);

        return $this->renderJavascript($event->code);
    }

    public function image(SitesManagerTrackingCodeRequest $request, bool $https): string
    {
        $parameters = ['idsite' => $request->siteId, 'rec' => 1];
        if ($request->actionName !== null) {
            $parameters['action_name'] = urlencode(html_entity_decode($request->actionName, ENT_QUOTES | ENT_HTML401, 'UTF-8'));
        }

        if ($request->goalId !== false) {
            $parameters['idgoal'] = $request->goalId;
            if ($request->revenue !== false) {
                $parameters['revenue'] = $request->revenue;
            }
        }

        $event = new ImageTrackingCodeGenerating($request->matomoUrl, $parameters);
        $this->events->dispatch($event);
        [, $endpoint] = $this->endpoints($request->forceMatomoEndpoint);
        $url = ($https ? 'https://' : 'http://').rtrim($event->host, '/').'/'.$endpoint.'?'.http_build_query($event->parameters, '', '&', PHP_QUERY_RFC3986);
        $html = "<!-- Matomo Image Tracker-->\n<img referrerpolicy=\"no-referrer-when-downgrade\" src=\"".
            htmlspecialchars($url, ENT_COMPAT | ENT_HTML401, 'UTF-8')."\" style=\"border:0\" alt=\"\" />\n<!-- End Matomo -->";

        return htmlspecialchars($html, ENT_COMPAT | ENT_HTML401, 'UTF-8');
    }

    /** @return array{string, string} */
    private function endpoints(bool $force): array
    {
        $old = ! $force && version_compare($this->options->value('version_core') ?? '999.0.0', '3.7.0-b1', '<');

        return $old ? ['piwik.js', 'piwik.php'] : ['matomo.js', 'matomo.php'];
    }

    private function host(string $url): string
    {
        $url = str_starts_with($url, 'http') ? $url : 'http://'.$url;
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        return rtrim((is_string($host) ? $host : '').(is_string($path) ? $path : ''), '/');
    }

    /** @return list<string> */
    private function siteHosts(int $siteId): array
    {
        $hosts = [];
        foreach ($this->sites->urls($siteId) as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $path = parse_url($url, PHP_URL_PATH);
            $value = (is_string($host) ? $host : '').(is_string($path) && $path !== '/' ? rtrim($path, '/') : '');
            if ($value !== '') {
                $hosts[] = $value;
            }
        }

        return $hosts;
    }

    /** @param list<array{0: string, 1: string}> $variables */
    private function customVariables(array $variables, string $scope, string $description): string
    {
        if ($variables === []) {
            return '';
        }

        $result = '  // you can set up to 5 custom variables for each '.$description."\n";
        foreach ($variables as $index => $variable) {
            $result .= sprintf('  _paq.push(["setCustomVariable", %d, %s, %s, "%s"]);', $index + 1, json_encode($variable[0]), json_encode($variable[1]), $scope)."\n";
        }

        return $result;
    }

    /** @param array<string, bool|int|string> $code */
    private function renderJavascript(array $code): string
    {
        $protocol = (string) ($code['protocol'] ?? '//');
        $host = (string) ($code['piwikUrl'] ?? '');
        $trackerUrl = json_encode(
            $protocol.$host.'/',
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );
        $setTrackerUrl = 'var u='.($trackerUrl === false ? '""' : $trackerUrl).';';
        $result = "<!-- Matomo -->\n<script>\n  var _paq = window._paq = window._paq || [];\n  /* tracker methods like \"setCustomDimension\" should be called before \"trackPageView\" */\n".
            (string) ($code['options'] ?? '')."  _paq.push(['trackPageView']);\n  _paq.push(['enableLinkTracking']);\n  (function() {\n    {$setTrackerUrl}\n    ".
            (string) ($code['optionsBeforeTrackerUrl'] ?? '')."_paq.push(['setTrackerUrl', u+'".(string) $code['matomoPhpFilename']."']);\n    _paq.push(['setSiteId', '".(string) $code['idSite']."']);\n";
        if ((bool) ($code['loadAsync'] ?? true)) {
            $result .= "    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];\n    g.async=true; g.src=u+'".(string) $code['matomoJsFilename']."'; s.parentNode.insertBefore(g,s);\n";
        }

        $result .= "  })();\n</script>\n";
        if ((bool) ($code['trackNoScript'] ?? false)) {
            $result .= '<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="'.$protocol.$host.'/'.(string) $code['matomoPhpFilename'].'?idsite='.(string) $code['idSite'].'&amp;rec=1" style="border:0;" alt="" /></p></noscript>'."\n";
        }

        return $result.'<!-- End Matomo Code -->';
    }
}
