<?php

declare(strict_types=1);

namespace Neos\Neos\Controller\Module\Administration;

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
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SystemHealth\SystemHealthViewInterface;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;

class SystemHealthController extends AbstractModuleController
{
    public const FALLBACK_REFRESH_INTERVAL = 600;
    public const SORTING_BEFORE_PREFIX = 'before ';
    public const SORTING_AFTER_PREFIX = 'after ';

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\InjectConfiguration(path: "systemHealth.views")]
    protected array $configuredViews = [];

    protected $defaultViewObjectName = FusionView::class;

    protected $supportedMediaTypes = ['text/html'];

    protected $viewFormatToObjectNameMap = [
        'html' => FusionView::class,
        'htmx' => FusionView::class
    ];

    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);

        if ($view instanceof FusionView) {
            $view->setFusionPathPatterns(['resource://Neos.Neos/Private/Fusion/Backend/Module/SystemHealth']);

            $format = $this->request->getFormat();
            if ($format === 'htmx') {
                $view->setFusionPath('refresh');
            } else {
                $view->setFusionPath('index');
            }
        }
    }

    public function indexAction(?string $contentRepository = null): void
    {
        if ($contentRepository === null) {
            $queryParams = $this->request->getHttpRequest()->getQueryParams();
            $contentRepository = $queryParams['contentRepository'] ?? null;
        }

        $contentRepositoryId = $contentRepository !== null
            ? ContentRepositoryId::fromString($contentRepository)
            : SiteDetectionResult::fromRequest($this->request->getHttpRequest())->contentRepositoryId;

        $availableContentRepositories = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $crId) {
            $label = $this->getLabelForContentRepository($crId);
            $availableContentRepositories[] = [
                'id' => $crId->value,
                'label' => $label,
                'selected' => $crId->equals($contentRepositoryId),
            ];
        }

        $views = $this->getViewInstances($contentRepositoryId);
        $viewsData = [];
        $isHtmxRequest = $this->request->getFormat() === 'htmx';
        $requestedViewIdentifier = $isHtmxRequest ? ($this->request->getHttpRequest()->getQueryParams()['viewIdentifier'] ?? null) : null;

        foreach ($views as $view) {
            $currentViewIdentifier = $view->getViewIdentifier();

            if ($isHtmxRequest && $requestedViewIdentifier !== null && $currentViewIdentifier !== $requestedViewIdentifier) {
                continue;
            }

            $viewsData[] = [
                'identifier' => $currentViewIdentifier,
                'prototypeName' => $view->getFusionPrototypeName(),
                'data' => $view->getData(),
                'refreshInterval' => $view->getRefreshInterval(),
            ];
        }

        $sitesForCurrentCR = $this->getSitesWithDomainsForContentRepository($contentRepositoryId);

        $this->view->assignMultiple([
            'views' => $viewsData,
            'contentRepositories' => $availableContentRepositories,
            'selectedContentRepositoryId' => $contentRepositoryId->value,
            'sitesForCurrentCR' => $sitesForCurrentCR,
        ]);
    }

    /**
     * @return array<SystemHealthViewInterface>
     */
    protected function getViewInstances(ContentRepositoryId $contentRepositoryId): array
    {
        $viewsWithConfig = [];

        foreach ($this->configuredViews as $viewClassName => $config) {
            $enabled = is_array($config) ? ($config['enabled'] ?? true) : $config;
            $position = is_array($config) ? ($config['position'] ?? 100) : 100;

            if (!$enabled) {
                continue;
            }

            $viewInstance = $this->objectManager->get($viewClassName, $config, $contentRepositoryId);
            if ($viewInstance instanceof SystemHealthViewInterface) {
                $viewsWithConfig[] = [
                    'instance' => $viewInstance,
                    'className' => $viewClassName,
                    'position' => $position,
                ];
            }
        }

        $sortedViews = $this->sortViewsByPosition($viewsWithConfig);

        return array_map(fn($item) => $item['instance'], $sortedViews);
    }

    /**
     * @param array<int, array{instance: SystemHealthViewInterface, className: string, position: int|string}> $viewsWithConfig
     */
    protected function sortViewsByPosition(array $viewsWithConfig): array
    {
        $numericPositions = [];
        $beforeAfterPositions = [];

        foreach ($viewsWithConfig as $viewConfig) {
            if (is_numeric($viewConfig['position'])) {
                $numericPositions[] = $viewConfig;
            } else {
                $beforeAfterPositions[] = $viewConfig;
            }
        }

        usort($numericPositions, fn($a, $b) => $a['position'] <=> $b['position']);

        foreach ($beforeAfterPositions as $viewConfig) {
            $position = $viewConfig['position'];

            if (is_string($position)) {
                if (str_starts_with($position, self::SORTING_BEFORE_PREFIX)) {
                    $targetClass = $this->resolveClassName(substr($position, strlen(self::SORTING_BEFORE_PREFIX)));
                    $this->insertBefore($numericPositions, $viewConfig, $targetClass);
                } elseif (str_starts_with($position, self::SORTING_AFTER_PREFIX)) {
                    $targetClass = $this->resolveClassName(substr($position, strlen(self::SORTING_AFTER_PREFIX)));
                    $this->insertAfter($numericPositions, $viewConfig, $targetClass);
                } else {
                    $numericPositions[] = $viewConfig;
                }
            }
        }

        return $numericPositions;
    }

    protected function resolveClassName(string $name): string
    {
        $name = trim($name);

        if (str_contains($name, '\\')) {
            return $name;
        }

        foreach (array_keys($this->configuredViews) as $className) {
            if (str_ends_with($className, '\\' . $name)) {
                return $className;
            }
        }

        return $name;
    }

    /**
     * @param array<int, array{instance: SystemHealthViewInterface, className: string, position: int|string}> $views
     * @param array{instance: SystemHealthViewInterface, className: string, position: int|string} $viewConfig
     */
    protected function insertBefore(array &$views, array $viewConfig, string $targetClass): void
    {
        $index = $this->findViewIndex($views, $targetClass);
        if ($index !== null) {
            array_splice($views, $index, 0, [$viewConfig]);
        } else {
            $views[] = $viewConfig;
        }
    }

    /**
     * @param array<int, array{instance: SystemHealthViewInterface, className: string, position: int|string}> $views
     * @param array{instance: SystemHealthViewInterface, className: string, position: int|string} $viewConfig
     */
    protected function insertAfter(array &$views, array $viewConfig, string $targetClass): void
    {
        $index = $this->findViewIndex($views, $targetClass);
        if ($index !== null) {
            array_splice($views, $index + 1, 0, [$viewConfig]);
        } else {
            $views[] = $viewConfig;
        }
    }

    /**
     * @param array<int, array{instance: SystemHealthViewInterface, className: string, position: int|string}> $views
     */
    protected function findViewIndex(array $views, string $className): ?int
    {
        foreach ($views as $index => $viewConfig) {
            if ($viewConfig['className'] === $className) {
                return $index;
            }
        }
        return null;
    }

    protected function getLabelForContentRepository(ContentRepositoryId $contentRepositoryId): string
    {
        $sites = $this->siteRepository->findAll();
        $siteNames = [];

        /** @var Site $site */
        foreach ($sites as $site) {
            $siteContentRepositoryId = $site->getConfiguration()->contentRepositoryId;
            if ($siteContentRepositoryId->equals($contentRepositoryId)) {
                $siteNames[] = $site->getName();
            }
        }

        if (empty($siteNames)) {
            return $contentRepositoryId->value;
        }

        return $contentRepositoryId->value . ' (' . implode(', ', $siteNames) . ')';
    }

    /**
     * @return array<array{name: string, primaryDomainUrl: ?string}>
     */
    protected function getSitesWithDomainsForContentRepository(ContentRepositoryId $contentRepositoryId): array
    {
        $result = [];

        /** @var Site $site */
        foreach ($this->siteRepository->findAll() as $site) {
            if (!$site->getConfiguration()->contentRepositoryId->equals($contentRepositoryId)) {
                continue;
            }

            $primaryDomainUrl = null;
            if ($primaryDomain = $site->getPrimaryDomain()) {
                $primaryDomainUrl = $primaryDomain->getScheme() ?? 'http';
                $primaryDomainUrl .= '://';
                $primaryDomainUrl .= $primaryDomain->getHostname();

                $port = $primaryDomain->getPort();
                if ($port !== null) {
                    $primaryDomainUrl .= ":$port";
                }
            }

            $result[] = [
                'name' => $site->getName(),
                'primaryDomainUrl' => $primaryDomainUrl,
            ];
        }

        return $result;
    }
}
