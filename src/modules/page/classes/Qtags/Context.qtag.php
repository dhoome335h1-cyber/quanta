<?php
namespace Quanta\Qtags;

use Quanta\Common\Api;

/**
 * Renders the current context.
 */
class Context extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    if (!isset($_REQUEST['context']) || !is_scalar($_REQUEST['context'])) {
      return '';
    }

    // Context is request-controlled data. QtagFactory intentionally re-scans
    // rendered output to support authored nested Qtags, so returning request
    // text verbatim can turn data into executable Qtag markup on a later pass.
    // Escape HTML first, then neutralize Quanta delimiters before the value
    // returns to the transform loop.
    return Api::string_normalize(
      htmlspecialchars((string) $_REQUEST['context'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );
  }
}
