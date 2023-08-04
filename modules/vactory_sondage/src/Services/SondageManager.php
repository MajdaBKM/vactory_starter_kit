<?php

namespace Drupal\vactory_sondage\Services;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Component\Serialization\Json;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\vactory_decoupled\MediaFilesManager;
use Drupal\vactory_sondage\Event\VactorySondageVoteEvent;
use Drupal\vactory_sondage\Services\Exceptions\InvalidArgumentException;

/**
 * Sondage manager service class.
 */
class SondageManager {

  /**
   * Event dispatcher service.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * Media Files manager Service.
   *
   * @var \Drupal\vactory_decoupled\MediaFilesManager
   */
  protected $mediaFilesManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(ContainerAwareEventDispatcher $eventDispatcher, MediaFilesManager $mediaFilesManager) {
    $this->eventDispatcher = $eventDispatcher;
    $this->mediaFilesManager = $mediaFilesManager;
  }

  /**
   * Get given sondage statistics.
   */
  public function getStatistics($sondage) {
    $is_block_content = $sondage instanceof BlockContent;
    if (!$is_block_content || ($is_block_content && $sondage->bundle() !== 'vactory_sondage')) {
      $block_content_class = '\Drupal\block_content\Entity\BlockContent';
      throw new InvalidArgumentException(sprintf('Argument 1 of %s::getStatistics method should be an instance of %s with bundle "vactory_sondage".', static::class, $block_content_class));
    }
    $current_user = \Drupal::currentUser();
    $sondage_results = $sondage->get('field_sondage_results')->value;
    $sondage_results = isset($sondage_results) && !empty($sondage_results) ? $sondage_results : '[]';
    $sondage_results = json_decode($sondage_results, TRUE);
    $sondage_options = $sondage->get('field_sondage_options')->getValue();
    $all_votters = $sondage_results['all_votters'] ?? [];
    unset($sondage_results['all_votters']);
    $options = [];
    foreach ($sondage_results as $key => $result) {
      $sondage_option = array_filter($sondage_options, function ($option) use ($key) {
        return $option['option_value'] === $key;
      });
      $sondage_option = reset($sondage_option);
      $percentage = intval(round(($result['count'] / count($all_votters)) * 100));
      $type = isset($sondage_option['option_text']) && !empty($sondage_option['option_text']) ? 'text' : '';
      $type = isset($sondage_option['option_image']) && !empty($sondage_option['option_image']) ? 'image' : $type;
      $is_closed = $this->isSondageClosed($sondage);
      $options[$key] = [
        'type'                 => $type,
        'percentage'           => $percentage . '%',
        'votes'                => $result['count'],
        'is_current_user_vote' => in_array($current_user->id(), $result['votters']),
      ];
      if ($type === 'text') {
        $options[$key]['text'] = $sondage_option['option_text'];
      }
      if ($type === 'image') {
        $media = Media::load($sondage_option['option_image']);
        if ($media) {
          $fid = $media->get('field_media_image')->target_id;
          $alt = $media->get('field_media_image')->alt;
          $file = $fid ? File::load($fid) : NULL;
          $image_uri = '';
          if ($file) {
            $image_uri = $file->get('uri')->value;
          }
          $options[$key]['image']['uri'] = $image_uri;
          $options[$key]['image']['media'] = $this->mediaFilesManager->getMediaAbsoluteUrl($image_uri);
          $options[$key]['image']['alt'] = $alt;

        }
      }
    }
    foreach ($sondage_options as $option) {
      $key = $option['option_value'];
      if (!isset($options[$key])) {
        $type = !empty($option['option_text']) ? 'text' : 'image';
        $options[$key] = [
          'type'                 => $type,
          'percentage'           => '0%',
          'votes'                => 0,
          'is_current_user_vote' => FALSE,
        ];
        if ($type === 'text') {
          $options[$key]['text'] = $option['option_text'];
        }
        if ($type === 'image') {
          $media = Media::load($option['option_image']);
          if ($media) {
            $fid = $media->get('field_media_image')->target_id;
            $alt = $media->get('field_media_image')->alt;
            $file = $fid ? File::load($fid) : NULL;
            $image_uri = '';
            if ($file) {
              $image_uri = $file->get('uri')->value;
              $alt = $media->get('field_media_image')->alt;
            }
            $options[$key]['image']['uri'] = $image_uri;
            $options[$key]['image']['media'] = $this->mediaFilesManager->getMediaAbsoluteUrl($image_uri);
            $options[$key]['image']['alt'] = $alt;
          }
        }
      }
    }

    ksort($options);
    $options = array_values($options);
    $statistics = [
      'options'     => $options,
      'is_closed'   => $is_closed,
      'votes_count' => count($all_votters),
    ];
    return $statistics;
  }

  /**
   * Check if given sondage is closed or not yet.
   */
  public function isSondageClosed($sondage) {
    if ($sondage instanceof BlockContent && $sondage->bundle() === 'vactory_sondage') {
      return !$sondage->get('field_sondage_status')->value;
    }
    $block_content_class = '\Drupal\block_content\Entity\BlockContent';
    throw new InvalidArgumentException(sprintf('Argument 1 of %s::isSondageClosed method should be an instance of %s with bundle "vactory_sondage".', static::class, $block_content_class));
  }

  /**
   * Adding a vote.
   */
  public function vote($entity, $voted_option_value) {

    $current_user = \Drupal::currentUser();
    $user = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->load($current_user->id());

    $storage_results = $entity->get('field_sondage_results')->value;
    $storage_results = isset($storage_results) && !empty($storage_results) ? $storage_results : '[]';
    $storage_results = Json::decode($storage_results);
    $count = isset($storage_results[$voted_option_value]) ? $storage_results[$voted_option_value]['count'] + 1 : 1;
    $storage_results[$voted_option_value]['count'] = $count;
    $storage_results[$voted_option_value]['votters'][] = $current_user->id();
    $storage_results['all_votters'][] = $current_user->id();
    $event = new VactorySondageVoteEvent($entity, $user);
    $this->eventDispatcher->dispatch(VactorySondageVoteEvent::EVENT_NAME, $event);
    $entity->set('field_sondage_results', Json::encode($storage_results));
    $entity->save();
  }

}
