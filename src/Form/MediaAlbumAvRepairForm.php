<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_album_av\Service\MediaAlbumAvIntegrityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class MediaAlbumAvRepairForm extends FormBase {

  protected MediaAlbumAvIntegrityChecker $checker;

  public function __construct(MediaAlbumAvIntegrityChecker $checker) {
    $this->checker = $checker;
  }

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_album_av.integrity_checker')
    );
  }

  /**
   *
   */
  public function getFormId() {
    return 'media_album_av_repair_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $issues = $this->checker->check();

    if (!$issues) {
      $form['status'] = [
        '#markup' => '<p><strong>✅ Aucun problème détecté.</strong></p>',
      ];
      return $form;
    }

    $options = [];
    foreach ($issues as $nid => $data) {
      $options[$nid] = sprintf(
        '%s (NID %d) – %d media manquant(s)',
        $data['title'],
        $nid,
        count($data['broken'])
      );
    }

    $form['albums'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Albums with broken references'),
      '#options' => $options,
      '#default_value' => array_keys($options),
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['repair'] = [
      '#type' => 'submit',
      '#value' => $this->t('Repair selected albums'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancel'],
    ];

    return $form;
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue('albums'));
    $count = $this->checker->repair($selected);

    $this->messenger()->addStatus(
      $this->t('Removed @count broken media references.', ['@count' => $count])
    );

    \Drupal::logger('media_album_av')->warning(
      'Removed @count broken media references', ['@count' => $count]
    );

    $form_state->setRedirect('<current>');
  }

  /**
   *
   */
  public function cancel(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addWarning($this->t('Repair cancelled.'));
    $form_state->setRedirect('<front>');
  }

}
