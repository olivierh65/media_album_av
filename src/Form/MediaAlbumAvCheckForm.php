<?php

namespace Drupal\media_album_av\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_album_av\Service\MediaAlbumAvChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to check and display media album references.
 */
class MediaAlbumAvCheckForm extends FormBase {

  protected MediaAlbumAvChecker $checker;

  public function __construct(MediaAlbumAvChecker $checker) {
    $this->checker = $checker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_album_av.checker')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_album_av_check_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $albums = $this->checker->getAlbumsDetails();

    if (empty($albums)) {
      $form['status'] = [
        '#markup' => '<p><strong>ℹ️ ' . $this->t('No albums found.') . '</strong></p>',
      ];
      return $form;
    }

    $form['#attached']['library'][] = 'media_album_av/album_check';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('This form displays all media album references with their status and file information.') . '</p>',
    ];

    foreach ($albums as $nid => $album_data) {
      $form["album_$nid"] = [
        '#type' => 'details',
        '#title' => $this->t(
          '@title (NID: @nid) - @count media',
          [
            '@title' => $album_data['title'],
            '@nid' => $nid,
            '@count' => $album_data['media_count'],
          ]
        ),
        '#open' => FALSE,
      ];

      $rows = [];
      foreach ($album_data['media'] as $media_info) {
        $status_icon = $media_info['media_exists'] ? '✅' : '❌';
        $media_status_icon = $media_info['media_status'] === 'Published' ? '✅' : '⚠️';

        $files_list = '';
        if (!empty($media_info['files'])) {
          $files_list = '<ul>';
          foreach ($media_info['files'] as $file_info) {
            $file_status_icon = $file_info['exists'] ? '✅' : '❌';
            $file_perm_icon = $file_info['status'] === 'Permanent' ? '✅' : '⚠️';
            $files_list .= '<li>' . $file_perm_icon . ' ' . $file_info['filename']
              . ' (FID: ' . $file_info['fid'] . ', '
              . $file_perm_icon . ' ' . $file_info['status']
              . ', ' . $file_status_icon . ' ' . $file_info['size'] . ')</li>';
          }
          $files_list .= '</ul>';
        }
        else {
          $files_list = '<p><em>' . $this->t('No files') . '</em></p>';
        }

        $rows[] = [
          'delta' => $media_info['delta'],
          'media_id' => $media_info['media_id'],
          'media_name' => $status_icon . ' ' . $media_info['media_name'],
          'media_status' => $media_status_icon . ' ' . $media_info['media_status'],
          'files' => ['data' => ['#markup' => $files_list]],
        ];
      }

      $form["album_$nid"]['table'] = [
        '#type' => 'table',
        '#header' => [
          'delta' => $this->t('Delta'),
          'media_id' => $this->t('Media ID'),
          'media_name' => $this->t('Media Name'),
          'media_status' => $this->t('Status'),
          'files' => $this->t('Files'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No media found.'),
        '#attributes' => ['class' => ['media-album-av-check-table']],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
  }

}
