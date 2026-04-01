<?php

namespace Drupal\media_album_av;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Crypt;

/**
 *
 */
trait HmacValidatorTrait {

  /**
   *
   */
  protected function validateHmac(Request $request): bool {
    $token_sent = $request->query->get('s_t');
    $render_id  = $request->query->get('s_id');
    if (!$token_sent || !$render_id) {
      return FALSE;
    }
    $session_id  = \Drupal::service('session_manager')->getId();
    $private_key = \Drupal::service('private_key')->get();
    $expected    = Crypt::hmacBase64($render_id, $session_id . $private_key);
    return hash_equals($expected, $token_sent);
  }

}
