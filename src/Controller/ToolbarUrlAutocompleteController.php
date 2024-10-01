<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Controller;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Cache\CacheInterface;

/**
 * Returns responses for Neo | Toolbar routes.
 */
final class ToolbarUrlAutocompleteController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly MenuLinkTreeInterface $menuLinkTree,
    private readonly LocalTaskManagerInterface $localTaskManager,
    private readonly AccessManagerInterface $accessManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly CacheBackendInterface $cache
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('menu.link_tree'),
      $container->get('plugin.manager.menu.local_task'),
      $container->get('access_manager'),
      $container->get('current_route_match'),
      $container->get('cache.default')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request) {
    $results = [];
    $input = $request->query->get('q');

    if (!$input) {
      return new JsonResponse($results);
    }

    $cid = 'neo_toolbar:autocomplete';
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      $cacheableMetadata = new CacheableMetadata();
      $cacheableMetadata->addCacheTags(['routes']);
      $data = [];
      $data[] = [
        'value' => '/',
        'label' => 'Home',
      ];
      $menuIds = ['main', 'admin'];
      foreach ($menuIds as $menuName) {
        $tree = $this->getMenuTreeElements($menuName, $cacheableMetadata);

        foreach ($tree as $tree_element) {
          $link = $tree_element->link;
          $url = $link->getUrlObject()->toString();
          if (strpos($url, 'token=') !== FALSE) {
            continue;
          }
          $data[$url] = [
            'label' => $link->getTitle(),
            'value' => $url,
          ];

          $tasks = $this->getLocalTasksForRoute($link->getRouteName(), $link->getRouteParameters(), $cacheableMetadata);
          foreach ($tasks as $route_name => $task) {
            $url = $task['url']->toString();
            if (!isset($data[$url])) {
              $data[$url] = [
                'label' => $link->getTitle() . ': ' . $task['title'],
                'value' => $url,
              ];
            }
          }
        }
      }
      $this->cache->set($cid, $data, Cache::PERMANENT, $cacheableMetadata->getCacheTags());
    }

    $results = array_map(function ($item) {
      $item['label'] .= ' <small>(' . $item['value'] . ')</small>';
      return $item;
    }, array_values(array_filter($data, function ($item) use ($input) {
      return str_contains($item['label'], $input) || str_contains($item['value'], $input);
    })));

    $nodeStorage = $this->entityTypeManager()->getStorage('node');
    $query = $nodeStorage->getQuery();
    $ids = $query->condition('title', $input, 'CONTAINS')
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $ids ? $nodeStorage->loadMultiple($ids) : [];
    foreach ($nodes as $node) {
      switch ($node->isPublished()) {
        case TRUE:
          $availability = '✅';
          break;

        case FALSE:
        default:
          $availability = '🚫';
          break;
      }

      $label = [
        $node->getTitle(),
        '<small>(' . $node->id() . ')</small>',
        $availability,
      ];

      $results[] = [
        'value' => EntityAutocomplete::getEntityLabels([$node]),
        'label' => implode(' ', $label),
      ];
    }

    return new JsonResponse($results);
  }

  /**
   * Retrieves the menu tree elements for the given menu.
   *
   * Every element returned by this method is already access checked.
   *
   * @param string $menuName
   *   The menu name.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheableMetadata
   *   The cacheable metadata object to add cacheable dependencies.
   *
   * @return \Drupal\Core\Menu\MenuLinkTreeElement[]
   *   A flatten array of menu link tree elements for the given menu.
   */
  protected function getMenuTreeElements($menuName, CacheableMetadata $cacheableMetadata) {
    $parameters = new MenuTreeParameters();
    $tree = $this->menuLinkTree->load($menuName, $parameters);

    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkNodeAccess'],
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ['callable' => 'menu.default_tree_manipulators:flatten'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    // Top-level inaccessible links are *not* removed; it is up
    // to the code doing something with the tree to exclude inaccessible links.
    // @see menu.default_tree_manipulators:checkAccess
    foreach ($tree as $key => $link) {
      $cacheableMetadata->addCacheableDependency($link);
      if (!$link->access->isAllowed()) {
        unset($tree[$key]);
      }
    }

    return $tree;
  }

  /**
   * Retrieve all the local tasks for a given route.
   *
   * Every element returned by this method is already access checked.
   *
   * @param string $route_name
   *   The route name for which find the local tasks.
   * @param array $route_parameters
   *   The route parameters.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheableMetadata
   *   The cacheable metadata object to add cacheable dependencies.
   *
   * @return array
   *   A flatten array that contains the local tasks for the given route.
   *   Each element in the array is keyed by the route name associated with
   *   the local tasks and contains:
   *     - title: the title of the local task.
   *     - url: the url object for the local task.
   *     - localized_options: the localized options for the local task.
   */
  protected function getLocalTasksForRoute($route_name, array $route_parameters, CacheableMetadata $cacheableMetadata) {
    $links = [];

    $tree = $this->localTaskManager->getLocalTasksForRoute($route_name);
    foreach ($tree as $instances) {
      /** @var \Drupal\Core\Menu\LocalTaskInterface[] $instances */
      foreach ($instances as $child) {
        $child_route_name = $child->getRouteName();
        // Merges the parent's route parameter with the child ones since you
        // calculate the local tasks outside of parent route context.
        $child_route_parameters = $child->getRouteParameters($this->routeMatch) + $route_parameters;
        $cacheableMetadata->addCacheableDependency($child);
        if ($this->accessManager->checkNamedRoute($child_route_name, $child_route_parameters)) {
          $links[$child_route_name] = [
            'title' => $child->getTitle(),
            'url' => Url::fromRoute($child_route_name, $child_route_parameters),
          ];
        }
      }
    }

    return count($links) > 1 ? $links : [];
  }

}
