<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Plugin\ContextAwarePluginTrait;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for neo_toolbar_item plugins.
 *
 * @phpstan-consistent-constructor
 */
abstract class ToolbarItemPluginBase extends PluginBase implements ToolbarItemPluginInterface {
  use DependencySerializationTrait;
  use RefinableCacheableDependencyTrait;
  use PluginWithFormsTrait;
  use ContextAwarePluginAssignmentTrait;
  use ContextAwarePluginTrait;
  use StringTranslationTrait;
  use ToolbarItemTokenTrait;

  /**
   * The context repository service.
   *
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  protected ContextRepositoryInterface $contextRepository;

  /**
   * The toolbar this plugin belongs to.
   *
   * @var \Drupal\neo_toolbar\ToolbarInterface
   */
  protected ToolbarInterface $toolbar;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected TransliterationInterface $transliteration;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->transliteration = $transliteration;
    $this->setConfiguration($configuration);
    $this->setDefinedContextValues();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('transliteration')
    );
  }

  /**
   * Set values for the defined contexts of this plugin.
   */
  protected function setDefinedContextValues() {
    $plugin_context_definitions = $this->getContextDefinitions();
    if (empty($plugin_context_definitions)) {
      return;
    }
    $available_contexts = $this->contextRepository()->getAvailableContexts();
    $available_runtime_contexts = $this->contextRepository()->getRuntimeContexts(array_keys($available_contexts));
    foreach ($plugin_context_definitions as $name => $plugin_context_definition) {
      $matches = $this->contextHandler()
        ->getMatchingContexts($available_runtime_contexts, $plugin_context_definition);
      $matching_context = reset($matches);
      $this->setContextValue($name, $matching_context->getContextValue());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function setToolbar(ToolbarInterface $toolbar): self {
    $this->addCacheableDependency($toolbar);
    $this->toolbar = $toolbar;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getToolbar(): ?ToolbarInterface {
    return $this->toolbar ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getStyle(): string {
    return 'default';
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(): string {
    return $this->pluginDefinition['provider'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCategory(): string {
    return $this->pluginDefinition['category'];
  }

  /**
   * {@inheritdoc}
   */
  public function getAlignment(): string {
    return $this->configuration['alignment'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string {
    return $this->tokenReplace($this->configuration['title']);
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(): string|null {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlaceholder(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getElements(): array {
    $element = $this->getElement();
    return [$element];
  }

  /**
   * Get the element that makes up the toolbar item.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   The toolbar item element.
   */
  protected function getElement(): ToolbarItemElement {
    $element = new ToolbarItemElement($this->getPluginId(), $this->getTitle(), $this->getAlignment());
    $element->setIcon($this->getIcon() ?? '');
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = NestedArray::mergeDeep(
      $this->baseConfigurationDefaults(),
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * Returns generic default configuration for item plugins.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  protected function baseConfigurationDefaults() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Creates a generic configuration form for all item types. Individual
   * item plugins can add elements to this form by overriding
   * ToolbarItemPluginBase::itemForm(). Most item plugins should not override
   * this method unless they need to alter the generic form elements.
   *
   * @see \Drupal\neo_toolbar\ToolbarItemPluginBase::itemForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?array &$complete_form = NULL) {
    $form += $this->itemForm($form, $form_state, $complete_form);

    // Add context mapping UI form elements.
    $contexts = $form_state->getTemporaryValue('gathered_contexts') ?: [];
    $form['context_mapping'] = $this->addContextAssignmentElement($this, $contexts);

    return $form;
  }

  /**
   * Configuration form for the toolbar item plugin.
   */
  protected function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Most item plugins should not override this method. To add validation
   * for a specific item type, override ToolbarItemPluginBase::itemValidate().
   *
   * @see \Drupal\neo_toolbar\ToolbarItemPluginBase::itemValidate()
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Remove the admin_label form item element value so it will not persist.
    $form_state->unsetValue('label');
    $this->itemValidate($form, $form_state);
  }

  /**
   * Form validation for the toolbar item plugin configuration.
   */
  protected function itemValidate(array $form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   *
   * Most item plugins should not override this method. To add submission
   * handling for a specific item type, override
   * ToolbarItemPluginBase::itemSubmit().
   *
   * @see \Drupal\neo_toolbar\ToolbarItemPluginBase::itemSubmit()
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Process the item's submission handling if no errors occurred only.
    if (!$form_state->getErrors()) {
      $this->itemSubmit($form, $form_state);
    }
  }

  /**
   * Form submit for the toolbar item plugin configuration.
   */
  protected function itemSubmit(array $form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    foreach ($this->getContextDefinitions() as $name => $definition) {
      if ($definition->isRequired()) {
        try {
          $this->getContextValue($name);
        }
        catch (ContextException $e) {
          return $return_as_object ? AccessResult::forbidden() : FALSE;
        }
      }
    }
    $access = $this->itemAccess($account);
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Indicates whether the item should be shown.
   *
   * Items with specific access checking should override this method rather
   * than access(), in order to avoid repeating the handling of the
   * $return_as_object argument.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user session for which to check access.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   *
   * @see self::access()
   */
  protected function itemAccess(AccountInterface $account) {
    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   */
  public function accessBySiblings(?ToolbarItemInterface $previous = NULL, ?ToolbarItemInterface $next = NULL): AccessResultInterface {
    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineNameSuggestion() {
    $definition = $this->getPluginDefinition();
    $label = $definition['label'];
    $transliterated = $this->transliteration->transliterate($label, LanguageInterface::LANGCODE_DEFAULT, '_');
    $transliterated = mb_strtolower($transliterated);
    $transliterated = preg_replace('@[^a-z0-9_.]+@', '', $transliterated);
    return $transliterated;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = $this->cacheTags;
    // Applied contexts can affect the cache tags when this plugin is
    // involved in caching, collect and return them.
    foreach ($this->getContexts() as $context) {
      /** @var \Drupal\Core\Cache\CacheableDependencyInterface $context */
      if ($context instanceof CacheableDependencyInterface) {
        $tags = Cache::mergeTags($tags, $context->getCacheTags());
      }
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $cache_contexts = $this->cacheContexts;
    // Applied contexts can affect the cache contexts when this plugin is
    // involved in caching, collect and return them.
    foreach ($this->getContexts() as $context) {
      /** @var \Drupal\Core\Cache\CacheableDependencyInterface $context */
      if ($context instanceof CacheableDependencyInterface) {
        $cache_contexts = Cache::mergeContexts($cache_contexts, $context->getCacheContexts());
      }
    }
    return $cache_contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    $max_age = $this->cacheMaxAge;
    // Applied contexts can affect the cache max age when this plugin is
    // involved in caching, collect and return them.
    foreach ($this->getContexts() as $context) {
      /** @var \Drupal\Core\Cache\CacheableDependencyInterface $context */
      if ($context instanceof CacheableDependencyInterface) {
        $max_age = Cache::mergeMaxAges($max_age, $context->getCacheMaxAge());
      }
    }
    return $max_age;
  }

  /**
   * Gets the context repository service.
   *
   * @return \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   *   The context repository service.
   */
  protected function contextRepository() {
    return $this->contextRepository ?? \Drupal::service('context.repository');
  }

}
