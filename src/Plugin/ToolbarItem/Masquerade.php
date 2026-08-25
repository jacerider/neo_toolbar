<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\masquerade\Form\MasqueradeForm;
use Drupal\masquerade\Masquerade as MasqueradeCore;
use Drupal\neo_icon\IconTrait;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_toolbar\ToolbarItemRegionTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'masquerade',
  label: new TranslatableMarkup('Masquerade'),
  description: new TranslatableMarkup('Masquerade integration.'),
  provider: 'masquerade',
  icon: 'user-secret',
)]
final class Masquerade extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use ToolbarItemRegionTrait;
  use IconTrait;

  /**
   * The masquerade service.
   *
   * @var \Drupal\masquerade\Masquerade
   */
  protected $masquerade;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    ?MasqueradeCore $masquerade = NULL,
    ?FormBuilderInterface $form_builder = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->masquerade = $masquerade;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('transliteration'),
      $container->get('masquerade'),
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermissions($account, $this->masquerade->getPermissions(), 'OR')
      ->addCacheContexts(['session.is_masquerading']);
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): ToolbarItemElement {
    $element = parent::getElement();

    $build = [
      '#type' => 'inline_template',
      '#template' => '
        <div class="mx-7">
          {{ content }}
        </div>
      ',
      '#cache' => [
        'tags' => ['session.is_masquerading'],
      ],
    ];

    if ($this->masquerade->isMasquerading()) {
      $build['#context']['content'] = [
        [
          '#lazy_builder' => ['masquerade.callbacks:renderSwitchBackLink', []],
          '#create_placeholder' => TRUE,
        ],
      ];
    }
    else {
      $build['#context']['content'] = $this->formBuilder->getForm(MasqueradeForm::class);
    }
    if ($this->masquerade->isMasquerading()) {
      $element->setTitle('Masquerading as ' . \Drupal::currentUser()->getDisplayName());
      $element->setBadge('✓');
    }
    $this->linkProcessElement($element, 'internal:/masquerade');
    $this->processRegionElementAsModal($element, $this->icon('Masquerade as User', $this->getIcon()), $build);
    // Open wider.
    $element->getModal()->setWidth('600px');
    return $element;
  }

}
