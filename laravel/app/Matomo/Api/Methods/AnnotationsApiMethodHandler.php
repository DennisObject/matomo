<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Annotations\AnnotationPeriodResolver;
use App\Matomo\Annotations\AnnotationRepository;
use App\Matomo\Api\AnnotationRequest;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

/** @phpstan-import-type Annotation from AnnotationRepository */
final readonly class AnnotationsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private AnnotationRepository $annotations,
        private AnnotationPeriodResolver $periods,
        private SiteRepository $sites,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isAnnotationsRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Annotations API handler does not support this request.');
        }

        $parameters = $request->annotations
            ?? throw new LogicException('The Annotations API parameters were not parsed.');
        $siteIds = $parameters->allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            )
            : $parameters->siteIds;

        if ($siteIds === []) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access.",
                401,
            );
        }

        if ($request->method === 'Annotations.deleteAll') {
            if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires a 'superuser' access.",
                    401,
                );
            }

            $siteId = $siteIds[0];

            if ($this->sites->timezone($siteId) === null) {
                return $this->responses->error(
                    $request,
                    "The website id = {$siteId} does not exist.",
                    400,
                );
            }

            $this->annotations->deleteAll($siteId);

            return $this->responses->success($request);
        }

        foreach ($siteIds as $siteId) {
            if (! $parameters->allSites
                && ! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                    401,
                );
            }

            if ($this->sites->timezone($siteId) === null) {
                return $this->responses->error(
                    $request,
                    "The website id = {$siteId} does not exist.",
                    400,
                );
            }
        }

        if ($request->method === 'Annotations.getAll') {
            return $this->all($request, $parameters, $siteIds);
        }

        if ($request->method === 'Annotations.getAnnotationCountForDates') {
            return $this->counts($request, $parameters, $siteIds);
        }

        $siteId = $siteIds[0];

        if ($request->method === 'Annotations.get') {
            return $this->one($request, $siteId, $parameters->noteId ?? 0);
        }

        if ($request->method === 'Annotations.add' && ! $this->hasWriteAccess($request, $siteId)) {
            return $this->writeAccessError($request, $siteId);
        }

        return match ($request->method) {
            'Annotations.add' => $this->add($request, $parameters, $siteId),
            'Annotations.save' => $this->save($request, $parameters, $siteId),
            'Annotations.delete' => $this->delete($request, $parameters, $siteId),
            default => throw new LogicException('The Annotations API method is not implemented.'),
        };
    }

    private function add(ApiRequest $request, AnnotationRequest $parameters, int $siteId): Response
    {
        try {
            $date = $this->date($parameters->date ?? '', $siteId);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        $annotation = $this->annotations->create(
            $siteId,
            $date,
            $this->note($parameters->note ?? ''),
            $parameters->starred ?? false,
            $this->authorizer->authenticatedLogin($request->authentication) ?? 'anonymous',
        );

        return $this->responses->row($request, $this->decorate($annotation, true));
    }

    private function save(ApiRequest $request, AnnotationRequest $parameters, int $siteId): Response
    {
        $noteId = $parameters->noteId ?? 0;
        $original = $this->annotations->find($siteId, $noteId);

        if ($original === null) {
            return $this->notFound($request, $siteId, $noteId);
        }

        if (! $this->hasWriteAccess($request, $siteId)) {
            return $this->writeAccessError($request, $siteId);
        }

        $values = [];

        if ($parameters->date !== null) {
            try {
                $values['date'] = $this->date($parameters->date, $siteId);
            } catch (InvalidArgumentException $invalidArgumentException) {
                return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
            }
        }

        if ($parameters->note !== null) {
            $values['note'] = $this->note($parameters->note);
        }

        if ($parameters->starred !== null) {
            $values['starred'] = (int) $parameters->starred;
        }

        $updated = $this->annotations->update($siteId, $noteId, $values) ?? $original;

        return $this->responses->row($request, $this->decorate($updated, true));
    }

    private function delete(ApiRequest $request, AnnotationRequest $parameters, int $siteId): Response
    {
        $noteId = $parameters->noteId ?? 0;

        if ($this->annotations->find($siteId, $noteId) === null) {
            return $this->notFound($request, $siteId, $noteId);
        }

        if (! $this->hasWriteAccess($request, $siteId)) {
            return $this->writeAccessError($request, $siteId);
        }

        $this->annotations->delete($siteId, $noteId);

        return $this->responses->success($request);
    }

    private function one(ApiRequest $request, int $siteId, int $noteId): Response
    {
        $annotation = $this->annotations->find($siteId, $noteId);

        return $annotation === null
            ? $this->notFound($request, $siteId, $noteId)
            : $this->responses->row(
                $request,
                $this->decorate($annotation, $this->hasWriteAccess($request, $siteId)),
            );
    }

    /** @param list<int> $siteIds */
    private function all(ApiRequest $request, AnnotationRequest $parameters, array $siteIds): Response
    {
        $result = [];

        foreach ($siteIds as $siteId) {
            try {
                [$start, $end] = $this->periods->range(
                    $siteId,
                    $parameters->date,
                    $parameters->period,
                    $parameters->lastN,
                );
            } catch (InvalidArgumentException $invalidArgumentException) {
                return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
            }

            $canEdit = $this->hasWriteAccess($request, $siteId);
            $result[$siteId] = array_map(
                fn (array $annotation): array => $this->decorate($annotation, $canEdit),
                $this->annotations->forSite($siteId, $start, $end),
            );
        }

        return $this->responses->structured($request, $result);
    }

    /** @param list<int> $siteIds */
    private function counts(ApiRequest $request, AnnotationRequest $parameters, array $siteIds): Response
    {
        $result = [];

        try {
            [$start, $end] = $this->periods->range(
                $siteIds[0],
                $parameters->date,
                $parameters->period,
                $parameters->lastN,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        if ($start === null || $end === null) {
            return $this->responses->structured($request, []);
        }

        $period = $parameters->period === 'range' ? 'day' : $parameters->period;
        $timezone = $this->sites->timezone($siteIds[0]) ?? 'UTC';
        $cursor = CarbonImmutable::parse($start, $timezone);
        $endDate = CarbonImmutable::parse($end, $timezone);

        foreach ($siteIds as $siteId) {
            $result[$siteId] = [];
            $periodStart = $cursor;
            $annotations = $this->annotations->forSite($siteId, $start, $end);
            $annotationIndex = 0;

            while ($periodStart->lessThanOrEqualTo($endDate)) {
                $next = match ($period) {
                    'day' => $periodStart->addDay(),
                    'week' => $periodStart->addWeek(),
                    'month' => $periodStart->addMonthNoOverflow(),
                    'year' => $periodStart->addYearNoOverflow(),
                    default => throw new LogicException("The annotation period '{$period}' is not supported."),
                };
                $total = 0;
                $starred = 0;
                $singleNote = null;

                while (isset($annotations[$annotationIndex])) {
                    $annotation = $annotations[$annotationIndex];
                    $annotationDate = CarbonImmutable::parse($annotation['date'], $timezone);

                    if ($annotationDate->greaterThanOrEqualTo($next)) {
                        break;
                    }

                    $annotationIndex++;

                    if ($annotationDate->lessThan($periodStart)) {
                        continue;
                    }

                    $total++;
                    $starred += $annotation['starred'] === 1 ? 1 : 0;
                    $singleNote = $annotation['note'];
                }

                $values = ['count' => $total, 'starred' => $starred];

                if ($parameters->includeText && $total === 1 && $singleNote !== null) {
                    $values['note'] = $this->sanitize($singleNote);
                }

                $result[$siteId][] = [$periodStart->toDateString(), $values];
                $periodStart = $next;
            }
        }

        return $this->responses->structured($request, $result);
    }

    private function hasWriteAccess(ApiRequest $request, int $siteId): bool
    {
        return $this->authorizer->hasSuperUserAccess($request->authentication)
            || in_array(
                $siteId,
                $this->authorizer->siteIdsWithMinimumRole(
                    $request->authentication,
                    SiteAccessRole::Write,
                ),
                true,
            );
    }

    private function date(string $date, int $siteId): string
    {
        [$start] = $this->periods->range($siteId, $date, 'day', null);

        return $start ?? throw new InvalidArgumentException("The date '{$date}' is not valid.");
    }

    private function note(string $note): string
    {
        return mb_strlen($note) > 255 ? mb_substr($note, 0, 254).'…' : $note;
    }

    /**
     * @param  Annotation  $annotation
     * @return array{id: int, idNote: int, idsite: int, date: string, note: string, starred: int, user: string, canEditOrDelete: bool}
     */
    private function decorate(array $annotation, bool $canEdit): array
    {
        return [
            ...$annotation,
            'date' => substr($annotation['date'], 0, 10),
            'note' => $this->sanitize($annotation['note']),
            'canEditOrDelete' => $canEdit,
            'idNote' => $annotation['id'],
        ];
    }

    private function sanitize(string $note): string
    {
        return htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    private function notFound(ApiRequest $request, int $siteId, int $noteId): Response
    {
        return $this->responses->error(
            $request,
            "There is no note with id '{$noteId}' for site with id '{$siteId}'.",
            400,
        );
    }

    private function writeAccessError(ApiRequest $request, int $siteId): Response
    {
        return $this->responses->error(
            $request,
            "The current user is not allowed to modify notes for site #{$siteId}.",
            401,
        );
    }
}
