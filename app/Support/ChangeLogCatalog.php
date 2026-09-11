<?php

namespace App\Support;

use App\Utils\ModuleUtil;
use Illuminate\Support\Collection;

class ChangeLogCatalog
{
    public function __construct(private ModuleUtil $moduleUtil)
    {
    }

    public function publishedFor($user): Collection
    {
        return collect(config('change_log.releases', []))
            ->filter(fn (array $release): bool => $release['status'] === 'published')
            ->filter(fn (array $release): bool => $this->isVisible($release, $user))
            ->sortByDesc(fn (array $release) => [$release['order'], $release['published_at']])
            ->values();
    }

    public function findPublishedFor(string $slug, $user): ?array
    {
        return $this->publishedFor($user)
            ->first(fn (array $release): bool => $release['slug'] === $slug);
    }

    public function publishedReleaseIdsFor($user): Collection
    {
        return $this->publishedFor($user)
            ->pluck('release_id')
            ->filter(fn ($releaseId): bool => is_string($releaseId) && $releaseId !== '')
            ->values();
    }

    public function visibleLinks(array $release, $user): Collection
    {
        return collect($release['related_links'] ?? [])
            ->filter(fn (array $link): bool => $this->isVisible($link, $user))
            ->map(fn (array $link): array => $link + ['url' => route($link['route'], $link['params'] ?? [])])
            ->values();
    }

    public function guidesFor($user): Collection
    {
        return collect(config('guides.articles', []))
            ->filter(fn (array $guide): bool => ($guide['status'] ?? 'published') === 'published')
            ->filter(fn (array $guide): bool => $this->isVisible($guide, $user))
            ->sortBy('order')
            ->values();
    }

    public function findGuideFor(string $slug, $user): ?array
    {
        return $this->guidesFor($user)
            ->first(fn (array $guide): bool => ($guide['slug'] ?? '') === $slug);
    }

    public function guidesForRelease(string $releaseSlug, $user): Collection
    {
        return $this->guidesFor($user)
            ->filter(fn (array $guide): bool => ($guide['release_slug'] ?? '') === $releaseSlug)
            ->values();
    }

    private function isVisible(array $entry, $user): bool
    {
        if (! empty($entry['module']) && ! $this->moduleUtil->isModuleInstalled($entry['module'])) {
            return false;
        }

        if (! empty($entry['permission']) && ! $user->can($entry['permission'])) {
            return false;
        }

        $subscriptionPermission = $entry['subscription_permission'] ?? null;
        if (! empty($subscriptionPermission)) {
            $businessId = (int) session('user.business_id');

            return $this->moduleUtil->isSubscribed($businessId)
                && $this->moduleUtil->hasThePermissionInSubscription($businessId, $subscriptionPermission);
        }

        return true;
    }
}
