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

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\Module\Administration\SystemHealthController;
use Neos\Neos\Domain\Service\WorkspaceStatisticsService;

/**
 * System Health view for Workspace Statistics
 *
 * Displays workspace statistics and total node count
 */
class WorkspaceStatisticsView implements SystemHealthViewInterface
{
    #[Flow\Inject]
    protected WorkspaceStatisticsService $workspaceStatisticsService;

    private ?int $refreshInterval = SystemHealthController::FALLBACK_REFRESH_INTERVAL;
    private ContentRepositoryId $contentRepositoryId;

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
        return 'workspaceStatistics';
    }

    public function getFusionPrototypeName(): string
    {
        return 'Neos.Neos:Module.Administration.SystemHealth.WorkspaceStatisticsCard';
    }

    public function getData(): array
    {
        return [
            'workspaceStatistics' => $this->workspaceStatisticsService->getWorkspaceStatistics($this->contentRepositoryId),
            'totalNodes' => $this->workspaceStatisticsService->getTotalNodeCount($this->contentRepositoryId),
            'outdatedWorkspaces' => $this->workspaceStatisticsService->getOutdatedWorkspacesWithoutChanges($this->contentRepositoryId),
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];
    }

    public function getRefreshInterval(): ?int
    {
        return $this->refreshInterval;
    }
}
