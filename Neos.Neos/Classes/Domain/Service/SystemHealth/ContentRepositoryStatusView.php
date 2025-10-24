<?php

declare(strict_types=1);

namespace Neos\Neos\Domain\Service\SystemHealth;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\Module\Administration\SystemHealthController;

/**
 * System Health view for Content Repository Status
 *
 * Displays event store and projection subscription status
 */
class ContentRepositoryStatusView implements SystemHealthViewInterface
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    private ?int $refreshInterval = SystemHealthController::FALLBACK_REFRESH_INTERVAL;
    private ContentRepositoryId $contentRepositoryId;

    /**
     * @param array{enabled: bool, refreshInterval: int, position: int|string} $viewConfiguration
     */
    public function __construct(
        array $viewConfiguration = [],
        ?ContentRepositoryId $contentRepositoryId = null,
    )
    {
        if (array_key_exists('refreshInterval', $viewConfiguration)) {
            $this->refreshInterval = $viewConfiguration['refreshInterval'];
        }
        $this->contentRepositoryId = $contentRepositoryId ?? ContentRepositoryId::fromString('default');
    }

    public function getViewIdentifier(): string
    {
        return 'contentRepositoryStatus';
    }

    public function getFusionPrototypeName(): string
    {
        return 'Neos.Neos:Module.Administration.SystemHealth.ContentRepositoryStatusCard';
    }

    public function getData(): array
    {
        $contentRepositoryMaintainer = $this->contentRepositoryRegistry->buildService(
            $this->contentRepositoryId,
            new ContentRepositoryMaintainerFactory()
        );

        $crStatus = $contentRepositoryMaintainer->status();

        $subscriptions = [];
        foreach ($crStatus->subscriptionStatus as $status) {
            if ($status instanceof ProjectionSubscriptionStatus) {
                $subscriptions[] = [
                    'id' => $status->subscriptionId->value,
                    'setupStatus' => $status->setupStatus->type->name,
                    'subscriptionStatus' => $status->subscriptionStatus->name,
                    'position' => $status->subscriptionPosition->value,
                    'error' => $status->subscriptionError?->errorMessage,
                    'isBehind' => $crStatus->eventStorePosition?->value > $status->subscriptionPosition->value,
                ];
            } elseif ($status instanceof DetachedSubscriptionStatus) {
                $subscriptions[] = [
                    'id' => $status->subscriptionId->value,
                    'setupStatus' => 'DETACHED',
                    'subscriptionStatus' => $status->subscriptionStatus->name,
                    'position' => $status->subscriptionPosition->value,
                    'error' => null,
                    'isBehind' => false,
                ];
            }
        }

        return [
            'eventStore' => [
                'status' => $crStatus->eventStoreStatus->type->name,
                'position' => $crStatus->eventStorePosition?->value ?? 0,
            ],
            'subscriptions' => $subscriptions,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];
    }

    public function getRefreshInterval(): ?int
    {
        return $this->refreshInterval;
    }
}
