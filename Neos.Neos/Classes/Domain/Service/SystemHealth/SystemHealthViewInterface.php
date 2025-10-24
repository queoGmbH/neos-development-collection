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

/**
 * Interface for System Health view components
 *
 * Each implementation represents one card/element on the System Health page
 */
interface SystemHealthViewInterface
{
    /**
     * Returns a unique identifier for this view
     * Used for JavaScript updates and CSS classes
     *
     * Example: 'workspaceStatistics', 'contentRepositoryStatus'
     *
     * @return string Unique view identifier
     */
    public function getViewIdentifier(): string;

    /**
     * Returns the Fusion prototype name for rendering this view
     *
     * Example: 'Neos.Neos:Module.Administration.SystemHealth.WorkspaceStatisticsCard'
     *
     * @return string Fully qualified Fusion prototype name
     */
    public function getFusionPrototypeName(): string;

    /**
     * Returns the data for rendering the view
     * Called by both initial rendering and HTMX refresh
     *
     * @return array Data to pass to the Fusion view
     */
    public function getData(): array;

    /**
     * Returns the refresh interval in seconds
     *
     * @return int|null Refresh interval
     */
    public function getRefreshInterval(): ?int;
}
