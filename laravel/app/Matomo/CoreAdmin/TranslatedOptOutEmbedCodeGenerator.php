<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

use App\Matomo\Api\OptOutEmbedRequest;
use App\Matomo\Localization\MatomoTranslator;
use InvalidArgumentException;
use JsonException;

final readonly class TranslatedOptOutEmbedCodeGenerator implements OptOutEmbedCodeGenerator
{
    private const string COMMON_JAVASCRIPT = <<<'JS'

        function showContent(consent, errorMessage = null, useTracker = false) {
            var errorBlock = '<p style="color: red; font-weight: bold;">';
            var div = document.getElementById(settings.divId);
            if (!div) {
                var warningDiv = document.createElement("div");
                var msg = 'Unable to find opt-out content div: "'+settings.divId+'"';
                warningDiv.id = settings.divId+'-warning';
                warningDiv.innerHTML = errorBlock+msg+'</p>';
                document.body.insertBefore(warningDiv, document.body.firstChild);
                console.log(msg);
                return;
            }

            if (!navigator || !navigator.cookieEnabled) {
                div.innerHTML = errorBlock+settings.OptOutErrorNoCookies+'</p>';
                return;
            }

            if (errorMessage !== null) {
                div.innerHTML = errorBlock+errorMessage+'</p>';
                return;
            }

            var content = '';

            if (location.protocol !== 'https:') {
                content += errorBlock + settings.OptOutErrorNotHttps + '</p>';
            }

            if (consent) {
                if (settings.showIntro) {
                    content += '<p>'+settings.YouMayOptOut2+' '+settings.YouMayOptOut3+'</p>';
                }
                content += '<input id="trackVisits" type="checkbox" checked="checked" />';
                content += '<label for="trackVisits"><strong><span>'+settings.YouAreNotOptedOut+' '+settings.UncheckToOptOut+'</span></strong></label>';
            } else {
                if (settings.showIntro) {
                    content += '<p>'+settings.OptOutComplete+' '+settings.OptOutCompleteBis+'</p>';
                }
                content += '<input id="trackVisits" type="checkbox" />';
                content += '<label for="trackVisits"><strong><span>'+settings.YouAreOptedOut+' '+settings.CheckToOptIn+'</span></strong></label>';
            }
            div.innerHTML = content;

            var tV = document.getElementById('trackVisits');
            if (consent) {
                if (useTracker) {
                    tV.addEventListener("click", function () {
                        _paq.push(['optUserOut']);
                        showContent(false, null, true);
                    });
                } else {
                    tV.addEventListener("click", function () {
                        window.MatomoConsent.consentRevoked();
                        showContent(false);
                    });
                }
            } else if (useTracker) {
                tV.addEventListener("click", function () {
                    _paq.push(['forgetUserOptOut']);
                    showContent(true, null, true);
                });
            } else {
                tV.addEventListener("click", function () {
                    window.MatomoConsent.consentGiven();
                    showContent(true);
                });
            }
        };

        window.MatomoConsent = {
            cookiesDisabled: (!navigator || !navigator.cookieEnabled),
            CONSENT_COOKIE_NAME: 'mtm_consent', CONSENT_REMOVED_COOKIE_NAME: 'mtm_consent_removed',
            cookieIsSecure: false, useSecureCookies: true, cookiePath: '', cookieDomain: '', cookieSameSite: 'Lax',
            init: function(useSecureCookies, cookiePath, cookieDomain, cookieSameSite) {
                this.useSecureCookies = useSecureCookies; this.cookiePath = cookiePath;
                this.cookieDomain = cookieDomain; this.cookieSameSite = cookieSameSite;
                if(useSecureCookies && location.protocol !== 'https:') {
                    console.log('Error with setting useSecureCookies: You cannot use this option on http.');
                } else {
                    this.cookieIsSecure = useSecureCookies;
                }
            },
            hasConsent: function() {
                var consentCookie = this.getCookie(this.CONSENT_COOKIE_NAME);
                var removedCookie = this.getCookie(this.CONSENT_REMOVED_COOKIE_NAME);
                if (!consentCookie && !removedCookie) {
                    return true;
                }
                if (removedCookie && consentCookie) {
                    this.setCookie(this.CONSENT_COOKIE_NAME, '', -129600000);
                    return false;
                }
                return (consentCookie || consentCookie !== 0);
            },
            consentGiven: function() {
                this.setCookie(this.CONSENT_REMOVED_COOKIE_NAME, '', -129600000);
                this.setCookie(this.CONSENT_COOKIE_NAME, new Date().getTime(), 946080000000);
            },
            consentRevoked: function() {
                this.setCookie(this.CONSENT_COOKIE_NAME, '', -129600000);
                this.setCookie(this.CONSENT_REMOVED_COOKIE_NAME, new Date().getTime(), 946080000000);
            },
            getCookie: function(cookieName) {
                var cookiePattern = new RegExp('(^|;)[ ]*' + cookieName + '=([^;]*)'), cookieMatch = cookiePattern.exec(document.cookie);
                return cookieMatch ? window.decodeURIComponent(cookieMatch[2]) : 0;
            },
            setCookie: function(cookieName, value, msToExpire) {
                var expiryDate = new Date();
                expiryDate.setTime((new Date().getTime()) + msToExpire);
                document.cookie = cookieName + '=' + window.encodeURIComponent(value) +
                    (msToExpire ? ';expires=' + expiryDate.toGMTString() : '') +
                    ';path=' + (this.cookiePath || '/') +
                    (this.cookieDomain ? ';domain=' + this.cookieDomain : '') +
                    (this.cookieIsSecure ? ';secure' : '') +
                    ';SameSite=' + this.cookieSameSite;
                if ((!msToExpire || msToExpire >= 0) && this.getCookie(cookieName) !== String(value)) {
                    console.log('There was an error setting cookie `' + cookieName + '`. Please check domain and path.');
                }
            }
        };
        JS;

    /** @param list<string> $trustedHosts */
    public function __construct(
        private MatomoTranslator $translator,
        private array $trustedHosts,
        private bool $trustedHostCheckEnabled,
    ) {}

    public function javascript(OptOutEmbedRequest $options): string
    {
        $matomoUrl = $this->validatedUrl($options->matomoUrl ?? '');
        $query = [
            'module' => 'CoreAdminHome',
            'action' => 'optOutJS',
            'divId' => 'matomo-opt-out',
            'language' => $options->language ?? '',
        ];

        if ($options->applyStyling) {
            $query += [
                'backgroundColor' => $options->backgroundColor,
                'fontColor' => $options->fontColor,
                'fontSize' => $options->fontSize,
                'fontFamily' => $options->fontFamily,
            ];
        }

        $query['showIntro'] = $options->showIntro ? '1' : '0';
        $source = rtrim($matomoUrl, '/').'/index.php?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        return '<div id="matomo-opt-out"></div>'
            ."\n<script src=\"".htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></script>';
    }

    public function selfContained(OptOutEmbedRequest $options, string $language): string
    {
        $settings = [
            'showIntro' => $options->showIntro,
            'divId' => 'matomo-opt-out',
            'useSecureCookies' => true,
            'cookiePath' => $options->cookiePath === '' ? null : $options->cookiePath,
            'cookieDomain' => $options->cookieDomain === '' ? null : $options->cookieDomain,
            'cookieSameSite' => $options->cookieSameSite,
        ] + $this->translations($language);

        try {
            $settingsJson = json_encode(
                $settings,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT,
            );
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException('The opt-out settings are invalid.', previous: $jsonException);
        }

        $style = $options->applyStyling
            ? 'style="'.htmlspecialchars($this->styling($options), ENT_QUOTES, 'UTF-8').'"'
            : '';
        $commonJavascript = $this->commonJavascript();

        return <<<HTML
            <div id="matomo-opt-out" {$style}></div>
            <script>
                var settings = {$settingsJson};
                document.addEventListener('DOMContentLoaded', function() {
                    window.MatomoConsent.init(settings.useSecureCookies, settings.cookiePath, settings.cookieDomain, settings.cookieSameSite);
                    showContent(window.MatomoConsent.hasConsent());
                });

            {$commonJavascript}
            </script>
            HTML;
    }

    private function validatedUrl(string $url): string
    {
        $parsed = parse_url($url);

        if (! is_array($parsed)
            || ! isset($parsed['host'])
            || $parsed['host'] === ''
            || (isset($parsed['scheme'])
                && ! in_array(strtolower($parsed['scheme']), ['http', 'https'], true))
            || isset($parsed['user'])
            || isset($parsed['pass'])
            || isset($parsed['query'])
            || isset($parsed['fragment'])
            || ! $this->validHost($parsed['host'])) {
            throw new InvalidArgumentException('The provided URL is invalid.');
        }

        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']).'://' : '//';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        return $scheme.$parsed['host'].$port.$path;
    }

    private function validHost(string $host): bool
    {
        if (preg_match('/[\x00-\x1f\x7f]/D', $host) === 1
            || strpbrk($host, '`~!@#$%^&*()+={}\\|;"\'<>?,/ ') !== false) {
            return false;
        }

        if (! $this->trustedHostCheckEnabled || $this->trustedHosts === []) {
            return true;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach ($this->trustedHosts as $trustedHost) {
            $trustedHost = strtolower(rtrim($trustedHost, '.'));

            if ($host === $trustedHost || str_ends_with($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }

    private function styling(OptOutEmbedRequest $options): string
    {
        foreach ([
            'fontColor' => $options->fontColor,
            'backgroundColor' => $options->backgroundColor,
        ] as $key => $color) {
            if ($color !== '' && (! ctype_xdigit($color) || ! in_array(strlen($color), [3, 6], true))) {
                throw new InvalidArgumentException(
                    "The URL parameter {$key} value of '{$color}' is not valid. "
                    ."Expected value is for example 'ffffff' or 'fff'.\n",
                );
            }
        }

        $style = '';

        if ($options->fontSize !== '') {
            if (preg_match('/^[0-9]+[.]?[0-9]*(?:px|pt|em|rem|%)$/D', $options->fontSize) !== 1) {
                throw new InvalidArgumentException(
                    "The URL parameter fontSize value of '{$options->fontSize}' is not valid. "
                    ."Expected value is for example '15pt', '1.2em' or '13px'.\n",
                );
            }

            $style .= 'font-size: '.$options->fontSize.'; ';
        }

        if ($options->fontFamily !== '') {
            if (preg_match('/^[a-zA-Z0-9-\\ ,\'\"]+$/D', $options->fontFamily) !== 1) {
                throw new InvalidArgumentException(
                    "The URL parameter fontFamily value of '{$options->fontFamily}' is not valid. "
                    ."Expected value is for example 'sans-serif' or 'Monaco, monospace'.\n",
                );
            }

            $style .= 'font-family: '.$options->fontFamily.'; ';
        }

        if ($options->fontColor !== '') {
            $style .= 'color: #'.$options->fontColor.'; ';
        }

        if ($options->backgroundColor !== '') {
            $style .= 'background-color: #'.$options->backgroundColor.'; ';
        }

        return $style;
    }

    /** @return array<string, string> */
    private function translations(string $language): array
    {
        $keys = [
            'OptOutComplete',
            'OptOutCompleteBis',
            'YouMayOptOut2',
            'YouMayOptOut3',
            'OptOutErrorNoCookies',
            'OptOutErrorNotHttps',
            'YouAreNotOptedOut',
            'UncheckToOptOut',
            'YouAreOptedOut',
            'CheckToOptIn',
        ];
        $translations = [];

        foreach ($keys as $key) {
            $translations[$key] = $this->translator->translate('CoreAdminHome_'.$key, $language);
        }

        return $translations;
    }

    private function commonJavascript(): string
    {
        return self::COMMON_JAVASCRIPT;
    }
}
