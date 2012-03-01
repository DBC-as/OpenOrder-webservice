<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/** \brief
 * Use ESGAROTH_WRAPPER for javascript execution - look in "safe_mode_exec_dir" (php.ini)
 * for the prog.
 * The javascript may use services and their setup is found in the catalog refered
 * from ESGAROTH_WRAPPER
 */

require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/z3950_class.php');


class openOrder extends webServiceServer {

  public function __construct() {
    webServiceServer::__construct('openorder.ini');
    define('ESGAROTH_WRAPPER', $this->config->get_value('esgaroth_wrapper', 'setup'));
    define('TMP_PATH', $this->config->get_value('tmp_path', 'setup'));

    define(DEBUG_ON, $this->debug);
  }

  /** \brief
   *
   * Request:
   * - expectedDelivery
   * - latestProviderNote
   * - orderId
   * - providerAnswer
   * - providerAnswerDate
   * - providerAnswerReason
   * - providerOrderState
   * - requesterId
   * - responderId
   * Response:
   * - updateStatus
   * or
   * - error
   */
  public function answer($param) {
    $ar = &$ret->answerResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $ar->error->_value = 'authentication_error';
    else {
    // constraints
      if (empty($param->expectedDelivery->_value) &&
          in_array($param->providerAnswer->_value, array('hold_placed', 'will_supply'))) {
        $ar->error->_value = 'expectedDelivery is mandatory with specified providerAnswer';
      }
      elseif (empty($param->providerAnswerReason->_value) &&
          in_array($param->providerAnswer->_value, array('', 'unfilled', 'will_supply'))) {
        $ar->error->_value = 'providerAnswerReason is mandatory with specified providerAnswer';
      }
      else {
        $ubf = new DOMDocument('1.0', 'utf-8');
        $answer = $this->add_ubf_node($ubf, $ubf, 'answer', '', TRUE);
        $this->add_ubf_node($ubf, $answer, 'expectedDelivery', $param->expectedDelivery->_value);
        $this->add_ubf_node($ubf, $answer, 'latestProviderNote', $param->latestProviderNote->_value);
        $this->add_ubf_node($ubf, $answer, 'orderId', $param->orderId->_value);
        $this->add_ubf_node($ubf, $answer, 'providerAnswer', $param->providerAnswer->_value);
        $this->add_ubf_node($ubf, $answer, 'providerAnswerDate', $param->providerAnswerDate->_value);
        $this->add_ubf_node($ubf, $answer, 'providerAnswerReason', $param->providerAnswerReason->_value);
        $this->add_ubf_node($ubf, $answer, 'providerOrderState', $param->providerOrderState->_value);
        $this->add_ubf_node($ubf, $answer, 'requesterId', $param->requesterId->_value);
        $this->add_ubf_node($ubf, $answer, 'responderId', $param->responderId->_value);
  
        $ubf_xml = $ubf->saveXML();
        if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
          $ar->error->_value = 'Order does not validate';
          verbose::log(FATAL, 'openorder:: answer: ' . $ar->error->_value);
        }
        else {
          if ($this->es_xmlupdate($ubf_xml)) {
            $ar->updateStatus->_value = 'update sent';
          } else {
            $ar->error->_value = 'service error';
          }
        }
      }
    }
    if (DEBUG_ON) {
      var_dump($ar);
      var_dump($param);
    }

    return $ret;
  }

  /** \brief Check order policy for a given Agency
   *
   * Request:
   * - serviceRequester
   * - bibliographicRecordId
   * - bibliographicRecordAgencyId
   * - pickUpAgencyId
   *
   * Response:
   * - lookUpUrl
   * - orderPossible
   * - orderPossibleReason
   * - orderCondition
   * or
   * - checkOrderPolicyError
   */
  public function checkOrderPolicy($param) {
    $copr = &$ret->checkOrderPolicyResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500)) {
      $copr->checkOrderPolicyError->_value = 'authentication_error';
    }
    elseif (empty($param->serviceRequester->_value)) {
      $copr->checkOrderPolicyError->_value = 'serviceRequester is obligatory';
    }
    else {
      $policy = $this->check_order_policy($param->bibliographicRecordId->_value,
                                          $this->strip_agency($param->bibliographicRecordAgencyId->_value),
                                          $this->strip_agency($param->pickUpAgencyId->_value),
                                          $param->serviceRequester->_value);
      verbose::log(DEBUG, 'openorder:: policy: ' . print_r($policy, TRUE));
      if ($policy['checkOrderPolicyError'])
        $copr->checkOrderPolicyError->_value = $policy['checkOrderPolicyError'];
      else {
        $notemap = $this->config->get_value('notemap', 'textmaps');
        $copr->lookUpUrl->_value = $policy['lookUpUrl'];
        $copr->orderPossible->_value = $policy['orderPossible'];
        if ($mapped_note = $notemap[ $policy['lookUpUrl'] ? 'url' : 'nourl' ]
                           [ strtolower($policy['orderPossible']) ]
                           [ strtolower($policy['orderPossibleReason']) ])
          $copr->orderPossibleReason->_value = $mapped_note;
        else
          $copr->orderPossibleReason->_value = $policy['orderPossibleReason'];
        if ($policy['orderConditionDanish']) {
          $cond_d->_attributes->language->_value = 'dan';
          $cond_d->_value = $policy['orderConditionDanish'];
          $copr->orderCondition[] = $cond_d;
        }
        if ($policy['orderConditionEnglish']) {
          $cond_e->_attributes->language->_value = 'eng';
          $cond_e->_value = $policy['orderConditionEnglish'];
          $copr->orderCondition[] = $cond_e;
        }
      }
    }

    if (DEBUG_ON) {
      var_dump($copr);
      var_dump($param);
    }

    return $ret;
  }


  /** \brief Place a ubfxml order using z3950 extend service
   *
   * Request:
   * - a lot of parameters, same as above and more - look in the xsd
   *
   * Response:
   * - orderPlaced
   *   - orderId
   *   - orderPlacedMessage (optional)
   * or
   * - orderNotPlaced
   *   - lookUpUrl (optional)
   *   - placeOrderError
   * - orderCondition
   */
  public function placeOrder($param) {
    $por = &$ret->placeOrderResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500)) {
      $por->orderNotPlaced->_value->placeOrderError->_value = 'authentication_error';
    }
    elseif (empty($param->serviceRequester->_value)) {
      $por->orderNotPlaced->_value->placeOrderError->_value = 'serviceRequester is obligatory';
    }
    else {
      if (isset($GLOBALS['HTTP_RAW_POST_DATA']))
        verbose::log(DEBUG, 'openorder:: xml: ' . $GLOBALS['HTTP_RAW_POST_DATA']);
      if ($param->pickUpAgencyId->_value) {
        $policy = $this->check_order_policy(
                    $param->bibliographicRecordId->_value,
                    $this->strip_agency($param->bibliographicRecordAgencyId->_value),
                    $this->strip_agency($param->pickUpAgencyId->_value),
                    $param->serviceRequester->_value);
      }
      elseif ($param->verificationReferenceSource->_value == 'none') {
        $policy = $this->check_nonVerifiedIll_order_policy($param->responderId->_value);
      }
      else
        $policy = $this->check_ill_order_policy(
                    $param->bibliographicRecordId->_value,
                    $this->strip_agency($param->bibliographicRecordAgencyId->_value),
                    $param->responderId->_value);
      verbose::log(DEBUG, 'openorder:: policy: ' . print_r($policy, TRUE));
      if ($policy['reason']) {
        $reason->_attributes->language->_value = 'dan';
        $reason->_value = $policy['reason'];
      }
      if ($policy['checkOrderPolicyError']) {
        $por->orderNotPlaced->_value->placeOrderError->_value = $policy['checkOrderPolicyError'];
        if ($reason) $por->reason = $reason;
      }
      elseif ($policy['orderPossible'] != 'TRUE') {
        $por->orderNotPlaced->_value->lookUpUrl->_value = $policy['lookUpUrl'];
        $por->orderNotPlaced->_value->placeOrderError->_value = $policy['orderPossibleReason'];
        if ($reason) $por->reason = $reason;
      }
      else {
        $ubf = new DOMDocument('1.0', 'utf-8');
        $order = $this->add_ubf_node($ubf, $ubf, 'order', '', TRUE);
        $this->add_ubf_node($ubf, $order, 'articleDirect', $param->articleDirect->_value);
        $this->add_ubf_node($ubf, $order, 'author', $param->author->_value);
        $this->add_ubf_node($ubf, $order, 'authorOfComponent', $param->authorOfComponent->_value);
        $this->add_ubf_node($ubf, $order, 'bibliographicCategory', $param->bibliographicCategory->_value);
        $this->add_ubf_node($ubf, $order, 'bibliographicRecordAgencyId', $param->bibliographicRecordAgencyId->_value);
        $this->add_ubf_node($ubf, $order, 'bibliographicRecordId', $param->bibliographicRecordId->_value);
        $this->add_ubf_node($ubf, $order, 'callNumber', $param->callNumber->_value);  // ??
        $this->add_ubf_node($ubf, $order, 'copy', $param->copy->_value);
        $this->add_ubf_node($ubf, $order, 'edition', $param->edition->_value);  // ??
        $this->add_ubf_node($ubf, $order, 'exactEdition', $param->exactEdition->_value);
        $this->add_ubf_node($ubf, $order, 'isbn', $param->isbn->_value);
        $this->add_ubf_node($ubf, $order, 'issn', $param->issn->_value);
        $this->add_ubf_node($ubf, $order, 'issue', $param->issue->_value);
        $this->add_ubf_node($ubf, $order, 'itemId', $param->itemId->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'language', $param->language->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'latestRequesterNote', $param->requesterNote->_value);
        $this->add_ubf_node($ubf, $order, 'localHoldingsId', $param->localHoldingsId->_value);
        $this->add_ubf_node($ubf, $order, 'mediumType', $param->mediumType->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'needBeforeDate', $param->needBeforeDate->_value);
        $this->add_ubf_node($ubf, $order, 'orderId', $param->orderId->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'orderSystem', $param->orderSystem->_value);
        $this->add_ubf_node($ubf, $order, 'pagination', $param->pagination->_value);
        $this->add_ubf_node($ubf, $order, 'pickUpAgencyId', $this->strip_agency($param->pickUpAgencyId->_value));
        $this->add_ubf_node($ubf, $order, 'pickUpAgencySubdivision', $param->pickUpAgencySubdivision->_value);
        $this->add_ubf_node($ubf, $order, 'placeOfPublication', $param->placeOfPublication->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'publicationDate', $param->publicationDate->_value);
        $this->add_ubf_node($ubf, $order, 'publicationDateOfComponent', $param->publicationDateOfComponent->_value);
        $this->add_ubf_node($ubf, $order, 'publisher', $param->publisher->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'requesterId', $param->requesterId->_value);
        $this->add_ubf_node($ubf, $order, 'responderId', $param->responderId->_value);
        $this->add_ubf_node($ubf, $order, 'seriesTitelNumber', $param->seriesTitelNumber->_value);
        //$this->add_ubf_node($ubf, $order, 'serviceRequester', $param->serviceRequester->_value);
        $this->add_ubf_node($ubf, $order, 'title', $param->title->_value);
        $this->add_ubf_node($ubf, $order, 'titleOfComponent', $param->titleOfComponent->_value);
        $this->add_ubf_node($ubf, $order, 'userAddress', $param->userAddress->_value);
        $this->add_ubf_node($ubf, $order, 'userAgencyId', $param->userAgencyId->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'userDateOfBirth', $param->userDateOfBirth->_value);
        $this->add_ubf_node($ubf, $order, 'userId', $param->userId->_value);
        if ($param->userId->_value)
          $this->add_ubf_node($ubf, $order, 'userIdAuthenticated', $this->xs_boolean($param->userIdAuthenticated->_value) ? 'yes' : 'no');
        $this->add_ubf_node($ubf, $order, 'userIdType', $param->userIdType->_value);
        $this->add_ubf_node($ubf, $order, 'userMail', $param->userMail->_value);
        $this->add_ubf_node($ubf, $order, 'userName', $param->userName->_value);
        $this->add_ubf_node($ubf, $order, 'userReferenceSource', $param->userReferenceSource->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'userTelephone', $param->userTelephone->_value);
        $this->add_ubf_node($ubf, $order, 'verificationReferenceSource', $param->verificationReferenceSource->_value);
        $this->add_ubf_node($ubf, $order, 'volume', $param->volume->_value);

        $ubf_xml = $ubf->saveXML();
        //echo 'ubf: <pre>' . $ubf_xml . "</pre>\n";
        if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
          $por->orderNotPlaced->_value->lookUpUrl->_value = $policy['lookUpUrl'];
          $por->orderNotPlaced->_value->placeOrderError->_value = 'Order does not validate';
        }
        else {
          if ($tgt_ref = $this->es_xmlupdate($ubf_xml, TRUE)) {
            $por->orderPlaced->_value->orderId->_value = $tgt_ref;
            if ($policy['orderPossibleReason']) {
              $notemap = $this->config->get_value('notemap', 'textmaps');
              if ($mapped_note = $notemap[ $policy['lookUpUrl'] ? 'url' : 'nourl' ]
                                 [ 'true' ]
                                 [ strtolower($policy['orderPossibleReason']) ])
                $por->orderPlaced->_value->orderPlacedMessage->_value = $mapped_note;
              else
                $por->orderPlaced->_value->orderPlacedMessage->_value = $policy['orderPossibleReason'];
            }
            else
              $por->orderPlaced->_value->orderPlacedMessage->_value = 'item available at pickupAgency, order accepted';
            if ($policy['orderConditionDanish']) {
              $cond_d->_attributes->language->_value = 'dan';
              $cond_d->_value = $policy['orderConditionDanish'];
              $por->orderCondition[] = $cond_d;
            }
            if ($policy['orderConditionEnglish']) {
              $cond_e->_attributes->language->_value = 'eng';
              $cond_e->_value = $policy['orderConditionEnglish'];
              $por->orderCondition[] = $cond_e;
            }
          }
          else {
            verbose::log(ERROR, 'openorder:: xml_itemorder status: ' . $z3950->get_error_string());
            $por->orderNotPlaced->_value->lookUpUrl->_value = $policy['lookUpUrl'];
            $por->orderNotPlaced->_value->placeOrderError->_value = 'Error sending order to ORS';
          }
          //var_dump($tgt_ref);
          //var_dump($z3950->get_error());
        }


      }
    }
    if (DEBUG_ON) {
      var_dump($por);
      var_dump($param);
    }
    return $ret;
  }

  /** \brief
   *
   * Request:
   * - messageType
   * - orderId
   * - requesterId
   * Response:
   * - updateStatus
   * or
   * - error
   */
  public function resend($param) {
    $rr = &$ret->resendResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $rr->error->_value = 'authentication_error';
    else {
      $ubf = new DOMDocument('1.0', 'utf-8');
      $resend = $this->add_ubf_node($ubf, $ubf, 'resend', '', TRUE);
      $this->add_ubf_node($ubf, $resend, 'messageType', $param->messageType->_value);
      $this->add_ubf_node($ubf, $resend, 'orderId', $param->orderId->_value);
      $this->add_ubf_node($ubf, $resend, 'requesterId', $param->requesterId->_value);

      $ubf_xml = $ubf->saveXML();
      if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
        $rr->error->_value = 'Order does not validate';
        verbose::log(FATAL, 'openorder:: answer: ' . $rr->error->_value);
      }
      else {
        if ($this->es_xmlupdate($ubf_xml)) {
          $rr->updateStatus->_value = 'update sent';
        } else {
          $rr->error->_value = 'service error';
        }
      }
    }
    if (DEBUG_ON) {
      var_dump($rr);
      var_dump($param);
    }

    return $ret;
  }

  /** \brief
   *
   * Request:
   * - creationDate
   * - dateDue
   * - itemId
   * - orderId
   * - requesterId
   * - responderId
   * - shippedDate
   * - shippedServiceType
   * Response:
   * - updateStatus
   * or
   * - error
   */
  public function shipped($param) {
    $sr = &$ret->shippedResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $sr->error->_value = 'authentication_error';
    else {
      $ubf = new DOMDocument('1.0', 'utf-8');
      $shipped = $this->add_ubf_node($ubf, $ubf, 'shipped', '', TRUE);
      $this->add_ubf_node($ubf, $shipped, 'creationDate', $param->creationDate->_value);
      $this->add_ubf_node($ubf, $shipped, 'dateDue', $param->dateDue->_value);
      $this->add_ubf_node($ubf, $shipped, 'itemId', $param->itemId->_value);
      $this->add_ubf_node($ubf, $shipped, 'orderId', $param->orderId->_value);
      $this->add_ubf_node($ubf, $shipped, 'requesterId', $param->requesterId->_value);
      $this->add_ubf_node($ubf, $shipped, 'responderId', $param->responderId->_value);
      $this->add_ubf_node($ubf, $shipped, 'shippedDate', $param->shippedDate->_value);
      $this->add_ubf_node($ubf, $shipped, 'shippedServiceType', $param->shippedServiceType->_value);

      $ubf_xml = $ubf->saveXML();
      if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
        $sr->error->_value = 'Order does not validate';
        verbose::log(FATAL, 'openorder:: answer: ' . $sr->error->_value);
      }
      else {
        if ($this->es_xmlupdate($ubf_xml)) {
          $sr->updateStatus->_value = 'update sent';
        } else {
          $sr->error->_value = 'service error';
        }
      }
    }
    if (DEBUG_ON) {
      var_dump($sr);
      var_dump($param);
    }

    return $ret;
  }

  /** \brief
   *
   * Request:
   * - orderId
   * - requesterId
   * - forwardOrderId
   * - closed
   * - providerOrderState
   * - requesterOrderState
   * Response:
   * - updateStatus
   * or
   * - error
   */
  public function updateOrder($param) {
    $uor = &$ret->answerResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $uor->error->_value = 'authentication_error';
    else {
      $ubf = new DOMDocument('1.0', 'utf-8');
      $update_order = $this->add_ubf_node($ubf, $ubf, 'updateOrder', '', TRUE);
      $this->add_ubf_node($ubf, $update_order, 'orderId', $param->orderId->_value);
      $this->add_ubf_node($ubf, $update_order, 'requesterId', $param->requesterId->_value);
      $this->add_ubf_node($ubf, $update_order, 'forwardOrderId', $param->forwardOrderId->_value);
      $this->add_ubf_node($ubf, $update_order, 'closed', $param->closed->_value);
      $this->add_ubf_node($ubf, $update_order, 'providerOrderState', $param->providerOrderState->_value);
      $this->add_ubf_node($ubf, $update_order, 'requesterOrderState', $param->requesterOrderState->_value);

      $ubf_xml = $ubf->saveXML();
      if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
        $uor->error->_value = 'Order does not validate';
        verbose::log(FATAL, 'openorder:: answer: ' . $uor->error->_value);
      }
      else {
        if ($this->es_xmlupdate($ubf_xml)) {
          $uor->updateStatus->_value = 'update sent';
        } else {
          $uor->error->_value = 'service error';
        }
      }
    }
    if (DEBUG_ON) {
      var_dump($uor);
      var_dump($param);
    }

    return $ret;
  }


  /*******************************************************************************/


  /** \brief Adds a ubf-text-node to a DOMDocument
   *
   * return the node created
   */
  private function add_ubf_node(&$dom, &$node, $tag, $value='', $create_empty_tag=FALSE) {
    if ($value || $create_empty_tag) {
      $help = $dom->createElementNS('http://www.dbc.dk/ubf', 'ubf:'.$tag, $value);
      $help = $node->appendChild($help);
      return $help;
    }
  }

  /** \brief Check nonVerifiedIll order policy for a given Agency
   *
   * return error-array or false
   */
  private function check_nonVerifiedIll_order_policy($responder_id) {
    $fname = TMP_PATH .  md5($responder_id . microtime(TRUE));
    $os_obj->receiverId = $responder_id;
    return $this->exec_order_policy($os_obj, $fname, 'nonVerifiedIll');
  }

  /** \brief Check ill order policy for a given Agency
   *
   * return error-array or false
   */
  private function check_ill_order_policy($record_id, $record_agency, $responder_id) {
    $fname = TMP_PATH .  md5($record_id .  $record_agency . $responder_id . microtime(TRUE));
    $os_obj->receiverId = $responder_id;
    $os_obj->bibliographicRecordId = $record_id;
    $os_obj->bibliographicRecordAgencyId = $record_agency;
    return $this->exec_order_policy($os_obj, $fname, 'ill');
  }

  /** \brief Check order policy for a given Agency
   *
   * return error-array or false
   */
  private function check_order_policy($record_id, $record_agency, $pickup_agency, $requester) {
    $fname = TMP_PATH .  md5($record_id .  $record_agency . $pickup_agency .  $requester . microtime(TRUE));
    $os_obj->serviceRequester = $requester;
    $os_obj->bibliographicRecordId = $record_id;
    $os_obj->pickUpAgencyId = $pickup_agency;
    $os_obj->bibliographicRecordAgencyId = $record_agency;
    return $this->exec_order_policy($os_obj, $fname);
  }

  /** \brief wrapper for z39.50 es xml update
   *
   * return target_reference or FALSE
   */
  private function es_xmlupdate(&$ubf_xml, $need_answer=FALSE) {
    $this->watch->start('xml_update');
    $z3950 = new z3950();
    $z3950->set_authentication($this->config->get_value('es_authentication', 'setup'), $_SERVER['REMOTE_ADDR']);
    $z3950->set_target($this->config->get_value('es_target', 'setup'));
    $z_result = $z3950->z3950_xml_update($ubf_xml, $this->config->get_value('es_timeout', 'setup'));
    verbose::log(DEBUG, 'openorder:: ubf: ' . $ubf_xml);
    verbose::log(DEBUG, 'openorder:: result: ' . str_replace("\n", '', print_r($z_result, TRUE)));
// test
//          $z_result = Array ("xmlUpdateDoc" => '<ors:orderResponse xmlns:ors="http://oss.dbc.dk/ns/openresourcesharing"><ors:orderId>1000000068</ors:orderId></ors:orderResponse>');
    $this->watch->stop('xml_update');
    if ($z3950->get_errno()) {
      verbose::log(FATAL, 'openorder:: es_xmlupdate returned error: ' . $z3950->get_error_string());
    }
    else {
      if ($resxml = $z_result['xmlUpdateDoc']) {
        $resdom = new DomDocument();
        if (@ $resdom->loadXML($resxml))
          if ($oid = $resdom->getElementsByTagName('orderId'))
            return $oid->item(0)->nodeValue;
      }
      return ! $need_answer;
    }
    return FALSE;
  }

  /** \brief wrapper for exec of esgaroth-shell
   *
   * Use external esgaroth program to facilitate javascripts
   *
   * return error-array or false
   */
  private function exec_order_policy(&$os_obj, $fname, $par='') {
    $f_in = $fname . '.in';
    $f_out = $fname . '.out';
    if ($fp = fopen($f_in, 'w')) {
      fwrite($fp, json_encode($os_obj));
      fclose($fp);
      $es_status = exec(ESGAROTH_WRAPPER ." $f_in $f_out $par");
      unlink($f_in);
      if ($es_status)
        verbose::log(ERROR, ESGAROTH_WRAPPER . ' returned error-code: ' . $es_status);
      if (is_file($f_out)) {
        $es_answer = json_decode(file_get_contents($f_out));
        unlink($f_out);
        if ($es_answer) {
          $ret['lookUpUrl'] = $es_answer->lookupUrl;
          $ret['orderPossible'] = ($es_answer->willReceive == 'true' ? 'TRUE' : 'FALSE');
          $ret['orderPossibleReason'] = $es_answer->note;
          $ret['orderConditionDanish'] = $es_answer->conditionDanish;
          $ret['orderConditionEnglish'] = $es_answer->conditionEnglish;
          $ret['reason'] = $es_answer->reason;
        }
        else
          $ret['checkOrderPolicyError'] = 'service unavailable';
      }
      else {
        $ret['checkOrderPolicyError'] = 'service unavailable';
        verbose::log(ERROR, ESGAROTH_WRAPPER . ' did not write an answer in ' . $f_out);
      }
    }
    else
      $ret['checkOrderPolicyError'] = 'service unavailable';

    //var_dump($es_answer);
    return $ret;
  }


  /** \brief
   *  return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

  /** \brief
   *  return only digits, so something like DK-710100 returns 710100
   */
  private function strip_agency($id) {
    return preg_replace('/\D/', '', $id);
  }

}

/**
 *   MAIN
 */

$ws=new openOrder();
$ws->handle_request();

?>

