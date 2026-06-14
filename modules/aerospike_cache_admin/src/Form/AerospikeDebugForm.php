<?php

namespace Drupal\aerospike_cache_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Toggles per-request Aerospike diagnostics (headers + toolbar) from the UI.
 *
 * The toggle is stored in State, so flipping it takes effect on the next
 * request with no deployment or cache rebuild. When settings.php defines
 * `aerospike_cache_debug`, that value wins and the toggle is shown disabled —
 * giving environments a hard override (for example, keeping production off).
 */
class AerospikeDebugForm extends FormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State key-value store backing the toggle.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings, checked for a settings.php override.
   */
  public function __construct(
    protected StateInterface $state,
    protected Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
      $container->get('settings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aerospike_cache_admin_debug';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $all = $this->settings->getAll();
    $forced = array_key_exists('aerospike_cache_debug', $all);
    $forcedValue = $forced && (bool) $all['aerospike_cache_debug'];

    if ($forced) {
      $form['override'] = [
        '#markup' => '<p>' . $this->t('Debug mode is currently <strong>@state</strong> via settings.php (the @key line), which overrides this toggle. Remove that line to control it here.', [
          '@state' => $forcedValue ? $this->t('ON') : $this->t('OFF'),
          '@key' => "\$settings['aerospike_cache_debug']",
        ]) . '</p>',
      ];
    }

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit per-request hit/miss headers and the toolbar tab'),
      '#default_value' => $forced ? $forcedValue : (bool) $this->state->get('aerospike_cache.debug', FALSE),
      '#disabled' => $forced,
      '#description' => $this->t('Adds X-Aerospike-Cache response headers (visible in browser dev tools, for HTML and JSON:API) and a live Aerospike toolbar tab. Development aid only — it is a no-op when off.'),
    ];

    if (!$forced) {
      $form['actions'] = [
        '#type' => 'actions',
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Save'),
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled = (bool) $form_state->getValue('enabled');
    $this->state->set('aerospike_cache.debug', $enabled);
    $this->messenger()->addStatus($enabled
      ? $this->t('Aerospike diagnostics enabled. Headers and the toolbar tab will appear on the next request.')
      : $this->t('Aerospike diagnostics disabled.'));
  }

}
