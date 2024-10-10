<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Menu\LocalActionManagerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Toolbar routes.
 */
final class ToolbarItemLibraryController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly ToolbarItemPluginManager $toolbarItemManager,
    private readonly ContextRepositoryInterface $contextRepository,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LocalActionManagerInterface $localActionManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.neo_toolbar_item'),
      $container->get('context.repository'),
      $container->get('current_route_match'),
      $container->get('plugin.manager.menu.local_action'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ToolbarInterface $neo_toolbar, string $neo_toolbar_region, Request $request): array {
    // Since modals do not render any other part of the page, we need to render
    // them manually as part of this listing.
    if ($request->query->get(MainContentViewSubscriber::WRAPPER_FORMAT) === 'drupal_modal') {
      $build['local_actions'] = $this->buildLocalActions();
    }

    $headers = [
      ['data' => $this->t('Item')],
      ['data' => $this->t('Category')],
      ['data' => $this->t('Operations')],
    ];

    // Only add blocks which work without any available context.
    $definitions = $this->toolbarItemManager->getDefinitionsForContexts($this->contextRepository->getAvailableContexts());
    // Order by category, and then by admin label.
    $definitions = $this->toolbarItemManager->getSortedDefinitions($definitions);

    $section = $request->query->get('section');
    $weight = $request->query->get('weight');
    $rows = [];

    foreach ($definitions as $plugin_id => $plugin_definition) {
      $row = [];
      $row['title']['data'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="block-filter-text-source">{{ label }}</div>',
        '#context' => [
          'label' => $plugin_definition['label'],
        ],
      ];
      $row['category']['data'] = $plugin_definition['category'];

      $links = [];
      $links['add'] = [
        'title' => $this->t('Place item'),
        'url' => Url::fromRoute('entity.neo_toolbar_item.add_form', [
          'neo_toolbar' => $neo_toolbar->id(),
          'neo_toolbar_region' => $neo_toolbar_region,
          'plugin_id' => $plugin_id,
        ]),
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => '100%',
            'height' => '100%',
          ]),
        ],
      ];
      if (isset($section)) {
        $links['add']['query']['section'] = $section;
      }
      if (isset($weight)) {
        $links['add']['query']['weight'] = $weight;
      }
      $row['operations']['data'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];
      $rows[] = $row;
    }

    $build['blocks'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No blocks available.'),
      '#attributes' => [
        'class' => ['block-add-table'],
      ],
    ];

    return $build;
  }

  /**
   * Builds the local actions for this listing.
   *
   * @return array
   *   An array of local actions for this listing.
   */
  protected function buildLocalActions() {
    $build = $this->localActionManager->getActionsForRoute($this->routeMatch->getRouteName());
    // Without this workaround, the action links will be rendered as <li> with
    // no wrapping <ul> element.
    if (!empty($build)) {
      $build['#prefix'] = '<ul class="action-links">';
      $build['#suffix'] = '</ul>';
    }
    return $build;
  }

}
