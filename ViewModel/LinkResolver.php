<?php declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: jeroen
 * Date: 11-4-19
 * Time: 11:45
 */

namespace Elgentos\PrismicIO\ViewModel;

use Elgentos\PrismicIO\Api\RouteRepositoryInterface;
use Elgentos\PrismicIO\Exception\RouteNotFoundException;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Prismic\LinkResolver as LinkResolverAbstract;

class LinkResolver extends LinkResolverAbstract
{

    /**
     * @var UrlInterface
     */
    private $urlBuilder;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var RouteRepositoryInterface
     */
    private $routeRepository;

    /**
     * @var UrlFinderInterface
     */
    private $urlFinder;

    public function __construct(
        UrlInterface $urlBuilder,
        StoreManagerInterface $storeManager,
        RouteRepositoryInterface $routeRepository,
        UrlFinderInterface $urlFinder
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->storeManager = $storeManager;
        $this->routeRepository = $routeRepository;
        $this->urlFinder = $urlFinder;
    }

    /**
     * Returns the application-specific URL related to this document link
     *
     *
     * @param object $link The document link
     *
     * @return string|null The URL of the link
     */
    public function resolve($link): ?string
    {
        $linkType = $link->link_type ?? 'Document';

        return $linkType === 'Media' ?
            $this->getMediaUrl($link) :
            $this->resolveRouteUrl($link);
    }

    public function getMediaUrl(\stdClass $link): ?string
    {
        return $link->url ?? null;
    }

    public function getStore(\stdClass $link): StoreInterface
    {
        $storeId = $link->store ?? $link->store_id ?? null;
        return $this->storeManager->getStore($storeId);
    }

    public function resolveRouteUrl(\stdClass $link): ?string
    {
        $uid = $link->uid ?? null;
        $contentType = $link->type ?? null;

        if (! $uid || !$contentType) {
            return $this->resolveDirectPage($link);
        }

        try {
            $store = $this->getStore($link);
            $route = $this->routeRepository->getByContentType((string)$contentType, +$store->getId());

            $data = ['_direct' => trim($route->getRoute(), '/') . '/' . $uid];
            return $this->getUrl($store, $data, '');
        } catch (RouteNotFoundException $e) {
            // Return direct page
            return $this->resolveDirectPage($link);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function resolveDirectPage(\stdClass $link): ?string
    {
        $store = $this->getStore($link);

        $id = $link->id ?? null;
        $uid = $link->uid ?? null;
        $contentType = $link->type ?? null;

        // Unable to resolve
        if (!$uid && !$id) {
            return '';
        }

        $data = [];
        $contentType && ($data['type'] = $contentType);

        if ($uid) {
            $data['uid'] = $uid;
        } else {
            $data['id'] = $uid;
        }

        return $this->getUrl($store, $data);
    }

    /**
     * Get direct url
     *
     * @param StoreInterface $store
     * @param array $data
     * @param string $routePath
     * @return string
     */
    public function getUrl(StoreInterface $store, array $data, $routePath = 'prismicio/direct/page'): string
    {
        $routeParams = [
            '_nosid' => true,
            '_scope' => $store
        ];
        $targetPath = $routePath . '/' . $this->createParams($data);

        if ($store->getConfig('web/default/front') === $targetPath) {
            // Homepage
            return $store->getBaseUrl();
        }

        $rewriteUrl = null;
        if ($routePath) {
            $rewriteUrl = $this->urlFinder->findOneByData([
                UrlRewrite::TARGET_PATH => $targetPath,
                UrlRewrite::IS_AUTOGENERATED => 0,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID => $store->getId()
            ]);
        }

        if ($rewriteUrl) {
            // Magento's url resolver is so stupid Framework/Url.php:748
            // Talking about single responsibility I would love to give this to Magento's URL resolver
            // I just cant because they forgot to forward a few parameters
            return rtrim($store->getBaseUrl() . $rewriteUrl->getRequestPath(), '/');
        }

        // Set route params and merge with requested parameters
        $routeParams = array_merge($routeParams, $data);
        return rtrim($this->urlBuilder->getUrl($routePath, $routeParams), '/');
    }

    /**
     * Generate route params
     *
     * @param array $data
     * @return string
     */
    private function createParams(array $data): string
    {
        $params = [];
        foreach ($data as $key => $value) {
            $params[] = $key . '/' . $value;
        }

        return implode('/', $params);
    }

}
