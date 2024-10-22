<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
  id: 'user',
  label: new TranslatableMarkup('User'),
  description: new TranslatableMarkup('The user account menu.'),
  region_create: TRUE,
)]
final class User extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use ToolbarItemRegionTrait;

  /**
   * The current user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $currentUser;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->currentUser = $entity_type_manager->getStorage('user')->load($current_user->id());
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
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    $complete_form['label'] = [
      '#type' => 'value',
      '#value' => 'User',
    ];

    $complete_form['id'] = [
      '#type' => 'value',
      '#value' => $complete_form['id']['#default_value'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return 'user-circle';
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    if ($account->isAnonymous()) {
      return AccessResult::forbidden();
    }
    return parent::itemAccess($account);
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $email = $this->currentUser->getEmail();
    $image = $this->getImage();
    $element->setTitle($this->t('Your Account'));
    $element->setImage($image);
    $this->linkProcessElement($element, 'internal:/user/' . $this->currentUser->id());
    $element->addCacheContexts(['user']);
    $this->processRegionElementAsModal($element, NULL, [
      'header' => [
        '#theme' => 'neo_toolbar_item_account_modal',
        '#image' => $image,
        '#name' => $this->currentUser->getDisplayName(),
        '#mail' => $email,
        '#weight' => -100,
      ],
    ]);
    return $element;
  }

  /**
   * Get the user image.
   *
   * @return string
   *   The image URL.
   */
  protected function getImage() {
    // Support user_picture field.
    if ($this->currentUser->hasField('user_picture') && !$this->currentUser->get('user_picture')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->currentUser->get('user_picture')->entity;
      if ($file) {
        return $file->getFileUri();
      }
    }
    return $this->getGravatar($this->currentUser->getEmail());
  }

  /**
   * Get a Gravatar URL for a specified email address.
   *
   * @param string $email
   *   The email address.
   * @param string $s
   *   Size in pixels, defaults to 80px [ 1 - 2048 ].
   * @param string $d
   *   Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ].
   * @param string $r
   *   Maximum rating (inclusive) [ g | pg | r | x ].
   *
   * @return string
   *   String containing either just a URL or a complete image tag
   */
  protected function getGravatar($email, $s = 50, $d = 'mm', $r = 'g') {
    $url = 'https://www.gravatar.com/avatar/';
    if (!empty($email)) {
      $url .= md5(strtolower(trim($email)));
    }
    $url .= "?s=$s&d=$d&r=$r";
    return $url;
  }

}
