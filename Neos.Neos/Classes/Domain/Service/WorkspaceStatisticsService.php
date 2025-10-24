<?php

declare(strict_types=1);

namespace Neos\Neos\Domain\Service;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStream;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\PendingChangesProjection\ChangeFinder;

/**
 * Service for collecting node and workspace statistics
 *
 * @Flow\Scope("singleton")
 */
final readonly class WorkspaceStatisticsService
{
    public function __construct(
        private ContentRepositoryRegistry $contentRepositoryRegistry,
    ) {
    }

    /**
     * Get statistics about unpublished changes per workspace
     *
     * @return array<string, array{workspaceName: string, nodeCount: int, percentage: float}>
     */
    public function getWorkspaceStatistics(
        ?ContentRepositoryId $contentRepositoryId = null
    ): array {
        $contentRepositoryId = $contentRepositoryId ?? ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        // Get the change finder projection state
        $changeFinder = $contentRepository->projectionState(ChangeFinder::class);

        // Get all workspaces
        $workspaces = $contentRepository->findWorkspaces();

        $workspaceChangeCounts = [];

        foreach ($workspaces as $workspace) {
            $workspaceName = $workspace->workspaceName;

            // Skip if workspace has no changes
            if (!$workspace->hasPublishableChanges()) {
                continue;
            }

            // Count pending changes using the ChangeFinder
            $changeCount = $changeFinder->countByContentStreamId($workspace->currentContentStreamId);

            if ($changeCount > 0) {
                $workspaceChangeCounts[$workspaceName->value] = $changeCount;
            }
        }

        // Calculate total and percentages
        $totalNodes = array_sum($workspaceChangeCounts);
        $statistics = [];

        foreach ($workspaceChangeCounts as $workspaceName => $nodeCount) {
            $percentage = $totalNodes > 0 ? ($nodeCount / $totalNodes) * 100 : 0;

            $statistics[$workspaceName] = [
                'workspaceName' => $workspaceName,
                'nodeCount' => $nodeCount,
                'percentage' => round($percentage, 2),
            ];
        }

        return $statistics;
    }

    /**
     * Get list of outdated workspaces without changes
     *
     * @return array<string, array{workspaceName: string}>
     */
    public function getOutdatedWorkspacesWithoutChanges(
        ?ContentRepositoryId $contentRepositoryId = null
    ): array {
        $contentRepositoryId = $contentRepositoryId ?? ContentRepositoryId::fromString('default');
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        // Get all workspaces
        $workspaces = $contentRepository->findWorkspaces();

        $outdatedWorkspaces = [];

        foreach ($workspaces as $workspace) {
            // Skip root workspaces (like 'live')
            if ($workspace->isRootWorkspace()) {
                continue;
            }

            // Check if workspace is outdated and has no changes
            if ($workspace->status === \Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceStatus::OUTDATED
                && !$workspace->hasPublishableChanges()) {
                $outdatedWorkspaces[$workspace->workspaceName->value] = [
                    'workspaceName' => $workspace->workspaceName->value,
                ];
            }
        }

        return $outdatedWorkspaces;
    }

    /**
     * Get the total number of nodes (sum of all changes across workspaces)
     *
     * This represents the total number of unpublished changes, which is the sum
     * of all individual workspace change counts shown in the statistics.
     */
    public function getTotalNodeCount(?ContentRepositoryId $contentRepositoryId = null): int
    {
        // Simply sum up all the node counts from workspace statistics
        $statistics = $this->getWorkspaceStatistics($contentRepositoryId);

        return array_sum(array_column($statistics, 'nodeCount'));
    }
}
