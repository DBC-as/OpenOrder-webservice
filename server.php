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
 * Use order_policy_shell for javascript execution - look in "safe_mode_exec_dir" (php.ini)
 * for the prog.
 * The javascript may use services and their setup is found in the catalog refered
 * from order_policy_shell
 */

/*
 - owned_accepted:                 item available at pickupAgency, order accepted
 - not_owned_ILL_loc:              item not available at pickupAgency, item localised for ILL
 - owned_wrong_mediumType:         item available at pickupAgency, order of mediumType not accepted
 - not_owned_wrong_ILL_mediumType: item not available at pickupAgency, ILL of mediumType not accepted
 - not_owned_no_ILL_loc:           item not available at pickupAgency, item not localised for ILL
 - owned_own_catalogue:            item available at pickupAgency, item may be ordered through the library's catalogue

 - service_unavailable:            service unavailable
            unknown_pickupAgency:           pickupAgency not found
            unknown_user:                   user not found
 - invalid_order:                  Order does not validate
 - ORS_error:                      Error sending order to ORS
 - no_serviceRequester:            serviceRequester is obligatory
 - authentication_error:           authentication error

*/
require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/z3950_class.php');


/** \brief
 * entry points: 
 *   answer() - answer an order
 *   checkElectronicDelivery() - Check whether articles from a journal (identified either by 
 *         it's PID or bibliographic record identifier) can be delivered electronically
 *   checkOrderPolicy() - check if a library accepts orders/ill for a given item
 *   placeOrder() - place an order
 *   resend() - resend an order
 *   shipped() - mark an order as shipped
 *   updateOrder() - update the status of an order
 */
class openOrder extends webServiceServer {
  protected $cache;
  protected $curl;
  protected $error_string;

  public function __construct() {
    webServiceServer::__construct('openorder.ini');
    define('ORDER_POLICY_SHELL', $this->config->get_value('order_policy_shell', 'setup'));
    define('TMP_PATH', $this->config->get_value('tmp_path', 'setup'));
    if ($host = $this->config->get_value('cache_host', 'setup')
     && $port = $this->config->get_value('cache_port', 'setup')
     && $expire = $this->config->get_value('cache_expire', 'setup'))
      $this->cache = new cache($host, $port, $expire);

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
   * - serviceRequester
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
      elseif (!$this->check_library_group($param->authentication->_value->groupIdAut->_value, $param->responderId->_value)) {
        $ar->error->_value = 'operation not authorized for specified responderId';
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
        $this->add_ubf_node($ubf, $answer, 'serviceRequester', $param->serviceRequester->_value);
  
        $ubf_xml = $ubf->saveXML();
        if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
          $ar->error->_value = 'invalid_order';
          verbose::log(FATAL, 'openorder:: answer: ' . $ar->error->_value);
        }
        else {
          if ($this->es_xmlupdate($ubf_xml)) {
            $ar->updateStatus->_value = 'update_sent';
          } else {
            $ar->error->_value = 'service_error';
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

  /** \brief Check whether articles from a journal (identified by it's ISSN) can be delivered electronically
   *
   * Request:
   * - issn
   * - serviceRequester
   *
   * Response:
   * - electronicDeliveryPossible
   * - electronicDeliveryPossibleReason
   * or
   * - error
   */
  public function checkElectronicDelivery($param) {
    $cedr = &$ret->checkElectronicDeliveryResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500)) {
      $cedr->error->_value = 'authentication_error';
    }
    else {
      $agency = '820010';  // sofar only 820010 can deliver electronically
      $issn = $this->normalize_issn($param->issn->_value);
      if ($this->cache) {
        $cache_key = 'OO_ced_' . $this->version . $param->serviceRequester->_value . 
                                                  $issn . 
                                                  $agency;
        if ($ret = $this->cache->get($cache_key)) {
          verbose::log(STAT, 'Cache hit');
          return $ret;
        }
      }
  // no cache, do the job
      if ($agency <> '820010') {
        $cedr->electronicDeliveryPossible->_value = '0';
        $cedr->electronicDeliveryPossibleReason->_value = 'no electronic supplier found';
      }
      else {
        require_once('OLS_class_lib/oci_class.php');
        $oci = new Oci($this->config->get_value('copydan_credentials','setup'));
        $oci->set_charset('UTF8');
        try {
          $oci->connect();
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenOrder('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
          $cedr->error->_value = 'service_unavailable';
        }
        try {
          $oci->bind('bind_issn', $issn);
          $oci->set_query('SELECT *
                             FROM copydan
                            WHERE issn = :bind_issn');
          if ($oci->fetch_into_assoc()) {
            $cedr->electronicDeliveryPossible->_value = '1';
          }
          else {
            $cedr->electronicDeliveryPossible->_value = '0';
            $cedr->electronicDeliveryPossibleReason->_value = 'issn not found';
          }
        }
        catch (ociException $e) {
          verbose::log(FATAL, 'OpenOrder('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
          $cedr->error->_value = 'service_unavailable';
        }

      }
    }

    if (DEBUG_ON) {
      var_dump($cedr);
      var_dump($param);
    }

    if ($cache_key && empty($cedr->error)) $this->cache->set($cache_key, $ret);

    return $ret;
  }


  /** \brief Check order policy for a given Agency
   *
   * Request:
   * - bibliographicRecordId
   * - bibliographicRecordAgencyId
   * - pickUpAgencyId
   * - pid
   * - serviceRequester
   *
   * Response:
   * - agencyCatalogueUrl
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
      $copr->checkOrderPolicyError->_value = 'no_serviceRequester';
    }
    else {
      if (!is_array($param->pid))
        $param->pid = array($param->pid);
      $policy = $this->check_order_policy($param->bibliographicRecordId->_value,
                                          $this->strip_agency($param->bibliographicRecordAgencyId->_value),
                                          $param->pid,
                                          $this->strip_agency($param->pickUpAgencyId->_value),
                                          $param->serviceRequester->_value);
      verbose::log(DEBUG, 'openorder:: policy: ' . print_r($policy, TRUE));
      if ($policy['checkOrderPolicyError'])
        $copr->checkOrderPolicyError->_value = $policy['checkOrderPolicyError'];
      else {
        $notemap = $this->config->get_value('notemap', 'textmaps');
        if ($policy['agencyCatalogueUrl'])
          $copr->agencyCatalogueUrl->_value = $policy['agencyCatalogueUrl'];
        if ($policy['lookUpUrls']) {
          foreach ($policy['lookUpUrls'] as $url) {
            $copr->lookUpUrl[]->_value = $url;
          }
        }
        $copr->orderPossible->_value = $policy['orderPossible'];
        if ($mapped_note = $notemap[ $policy['lookUpUrls'] ? 'url' : 'nourl' ]
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
   *   - agencyCatalogueUrl
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
      $por->orderNotPlaced->_value->placeOrderError->_value = 'no_serviceRequester';
    }
    else {
      if (isset($GLOBALS['HTTP_RAW_POST_DATA']))
        verbose::log(DEBUG, 'openorder:: xml: ' . $GLOBALS['HTTP_RAW_POST_DATA']);
      if (!is_array($param->pid))
        $param->pid = array($param->pid);
      if ($param->pickUpAgencyId->_value) {
        $policy = $this->check_order_policy(
                    $param->bibliographicRecordId->_value,
                    $this->strip_agency($param->bibliographicRecordAgencyId->_value),
                    $param->pid,
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
        if ($policy['agencyCatalogueUrl'])
          $por->orderNotPlaced->_value->agencyCatalogueUrl->_value = $policy['agencyCatalogueUrl'];
        if ($policy['lookUpUrls']) {
          foreach ($policy['lookUpUrls'] as $url) {
            $por->orderNotPlaced->_value->lookUpUrl[]->_value = $url;
          }
        }
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
        $this->add_ubf_node($ubf, $order, 'fullTextLink', $param->fullTextLink->_value);
        $this->add_ubf_node($ubf, $order, 'fullTextLinkType', $param->fullTextLinkType->_value);
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
        foreach ($param->pid as $p)
          $this->add_ubf_node($ubf, $order, 'pid', $p->_value);
        $this->add_ubf_node($ubf, $order, 'pickUpAgencyId', $this->strip_agency($param->pickUpAgencyId->_value));
        $this->add_ubf_node($ubf, $order, 'pickUpAgencySubdivision', $param->pickUpAgencySubdivision->_value);
        $this->add_ubf_node($ubf, $order, 'placeOfPublication', $param->placeOfPublication->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'publicationDate', $param->publicationDate->_value);
        $this->add_ubf_node($ubf, $order, 'publicationDateOfComponent', $param->publicationDateOfComponent->_value);
        $this->add_ubf_node($ubf, $order, 'publisher', $param->publisher->_value);		// ??
        $this->add_ubf_node($ubf, $order, 'requesterId', $param->requesterId->_value);
        $this->add_ubf_node($ubf, $order, 'responderId', $param->responderId->_value);
        $this->add_ubf_node($ubf, $order, 'seriesTitelNumber', $param->seriesTitelNumber->_value);
        $this->add_ubf_node($ubf, $order, 'serviceRequester', $param->serviceRequester->_value);
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
        //echo 'ubf: <pre>' . $ubf_xml . "</pre>\n"; die();
        if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
          if ($policy['agencyCatalogueUrl'])
            $por->orderNotPlaced->_value->agencyCatalogueUrl->_value = $policy['agencyCatalogueUrl'];
          if ($policy['lookUpUrls']) {
            foreach ($policy['lookUpUrls'] as $url) {
              $por->orderNotPlaced->_value->lookUpUrl[]->_value = $url;
            }
          }
          $por->orderNotPlaced->_value->placeOrderError->_value = 'invalid_order';
        }
        else {
          if ($tgt_ref = $this->es_xmlupdate($ubf_xml, TRUE)) {
            $por->orderPlaced->_value->orderId->_value = $tgt_ref;
            if ($policy['orderPossibleReason']) {
              $notemap = $this->config->get_value('notemap', 'textmaps');
              if ($mapped_note = $notemap[ $policy['lookUpUrls'] ? 'url' : 'nourl' ]
                                 [ 'true' ]
                                 [ strtolower($policy['orderPossibleReason']) ])
                $por->orderPlaced->_value->orderPlacedMessage->_value = $mapped_note;
              else
                $por->orderPlaced->_value->orderPlacedMessage->_value = $policy['orderPossibleReason'];
            }
            else
              $por->orderPlaced->_value->orderPlacedMessage->_value = 'owned_accepted';
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
            verbose::log(ERROR, 'openorder:: xml_itemorder status: ' . $this->error_string);
            if ($policy['lookUpUrls']) {
              foreach ($policy['lookUpUrls'] as $url) {
                $por->orderNotPlaced->_value->lookUpUrl[]->_value = $url;
              }
            }
            $por->orderNotPlaced->_value->placeOrderError->_value = 'ORS_error';
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
   * - responderId
   * - serviceRequester
   * Response:
   * - updateStatus
   * or
   * - error
   */
  public function resend($param) {
    $rr = &$ret->resendResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $rr->error->_value = 'authentication_error';
    elseif (in_array($param->messageType->_value, array('orsEndUserRequest', 'orsReceipt'))
        && !$this->check_library_group($param->authentication->_value->groupIdAut->_value, $param->requesterId->_value)) {
      $rr->error->_value = 'operation not authorized for specified requesterId';
    }
    elseif ($param->messageType->_value == 'orsInterLibraryRequest' 
        && !$this->check_library_group($param->authentication->_value->groupIdAut->_value, $param->responderId->_value)) {
      $rr->error->_value = 'operation not authorized for specified responderId';
    }
    else {
      $ubf = new DOMDocument('1.0', 'utf-8');
      $resend = $this->add_ubf_node($ubf, $ubf, 'resend', '', TRUE);
      $this->add_ubf_node($ubf, $resend, 'messageType', $param->messageType->_value);
      $this->add_ubf_node($ubf, $resend, 'orderId', $param->orderId->_value);
      $this->add_ubf_node($ubf, $resend, 'requesterId', $param->requesterId->_value);
      $this->add_ubf_node($ubf, $resend, 'serviceRequester', $param->serviceRequester->_value);

      $ubf_xml = $ubf->saveXML();
      if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
        $rr->error->_value = 'invalid_order';
        verbose::log(FATAL, 'openorder:: answer: ' . $rr->error->_value);
      }
      else {
        if ($this->es_xmlupdate($ubf_xml)) {
          $rr->updateStatus->_value = 'update_sent';
        } else {
          $rr->error->_value = 'service_error';
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
   * - serviceRequester
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
    elseif (!$this->check_library_group($param->authentication->_value->groupIdAut->_value, $param->responderId->_value)) {
      $sr->error->_value = 'operation not authorized for specified responderId';
    }
    else {
      $ubf = new DOMDocument('1.0', 'utf-8');
      $shipped = $this->add_ubf_node($ubf, $ubf, 'shipped', '', TRUE);
      $this->add_ubf_node($ubf, $shipped, 'creationDate', $param->creationDate->_value);
      $this->add_ubf_node($ubf, $shipped, 'dateDue', $param->dateDue->_value);
      $this->add_ubf_node($ubf, $shipped, 'itemId', $param->itemId->_value);
      $this->add_ubf_node($ubf, $shipped, 'orderId', $param->orderId->_value);
      $this->add_ubf_node($ubf, $shipped, 'requesterId', $param->requesterId->_value);
      $this->add_ubf_node($ubf, $shipped, 'responderId', $param->responderId->_value);
      $this->add_ubf_node($ubf, $shipped, 'serviceRequester', $param->serviceRequester->_value);
      $this->add_ubf_node($ubf, $shipped, 'shippedDate', $param->shippedDate->_value);
      $this->add_ubf_node($ubf, $shipped, 'shippedServiceType', $param->shippedServiceType->_value);

      $ubf_xml = $ubf->saveXML();
      if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
        $sr->error->_value = 'invalid_order';
        verbose::log(FATAL, 'openorder:: answer: ' . $sr->error->_value);
      }
      else {
        if ($this->es_xmlupdate($ubf_xml)) {
          $sr->updateStatus->_value = 'update_sent';
        } else {
          $sr->error->_value = 'service_error';
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
   * - responderId
   * - forwardOrderId
   * - closed
   * or
   * - providerOrderState
   * or
   * - requesterOrderState
   * - serviceRequester
   * Response:
   * - updateStatus
   * or
   * - error
   */
  public function updateOrder($param) {
    $uor = &$ret->updateOrderResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $uor->error->_value = 'authentication_error';
    elseif ((isset($param->closed->_value) || isset($param->requesterOrderState->_value))
        && !$this->check_library_group($param->authentication->_value->groupIdAut->_value, $param->requesterId->_value)) {
      $uor->error->_value = 'operation not authorized for specified requesterId';
    }
    elseif (isset($param->providerOrderState->_value)
        && !$this->check_library_group($param->authentication->_value->groupIdAut->_value, $param->responderId->_value)) {
      $uor->error->_value = 'operation not authorized for specified responderId';
    }
    else {
      $ubf = new DOMDocument('1.0', 'utf-8');
      $update_order = $this->add_ubf_node($ubf, $ubf, 'updateOrder', '', TRUE);
      $this->add_ubf_node($ubf, $update_order, 'orderId', $param->orderId->_value);
      $this->add_ubf_node($ubf, $update_order, 'requesterId', $param->requesterId->_value);
      $this->add_ubf_node($ubf, $update_order, 'forwardOrderId', $param->forwardOrderId->_value);
      $this->add_ubf_node($ubf, $update_order, 'closed', $param->closed->_value);
      $this->add_ubf_node($ubf, $update_order, 'providerOrderState', $param->providerOrderState->_value);
      $this->add_ubf_node($ubf, $update_order, 'requesterOrderState', $param->requesterOrderState->_value);
      $this->add_ubf_node($ubf, $update_order, 'serviceRequester', $param->serviceRequester->_value);

      $ubf_xml = $ubf->saveXML();
      if ($this->validate['ubf'] && !$this->validate_xml($ubf_xml, $this->validate['ubf'])) {
        $uor->error->_value = 'invalid_order';
        verbose::log(FATAL, 'openorder:: answer: ' . $uor->error->_value);
      }
      else {
        if ($this->es_xmlupdate($ubf_xml)) {
          $uor->updateStatus->_value = 'update_sent';
        } else {
          $uor->error->_value = 'service_error';
        }
      }
    }
    if (DEBUG_ON) {
      var_dump($uor);
      var_dump($param);
    }

    return $ret;
  }

  /** \brief
   *
   * Request:
   * - agencyId
   * Response:
   * - incrementRedirectStatStatus
   * or
   * - error
   */
  public function incrementRedirectStat($param) {
    $irss = &$ret->incrementRedirectStatResponse->_value;
    if (!$this->aaa->has_right('netpunkt.dk', 500))
      $irss->error->_value = 'authentication_error';
    else {
      $agency = $this->strip_agency($param->agencyId->_value);
      require_once('OLS_class_lib/oci_class.php');
      $oci = new Oci($this->config->get_value('redirect_credentials','setup'));
      $oci->set_charset('UTF8');
      try {
        $oci->connect();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenOrder('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
        $irss->error->_value = 'service_unavailable';
      }
      try {
        $oci->bind('bind_bib_nr', $agency);
        $oci->set_query('UPDATE redirect_stats
                            SET transactions = transactions+1
                          WHERE bib_nr = :bind_bib_nr
                            AND creation_date = trunc(sysdate)');
        if (! $oci->get_num_rows()) {
          $oci->bind('bind_bib_nr', $agency);
          $oci->set_query('INSERT INTO redirect_stats (bib_nr, transactions)
                           VALUES (:bind_bib_nr, 1)');
        }
        $oci->commit();
      }
      catch (ociException $e) {
        verbose::log(FATAL, 'OpenOrder('.__LINE__.'):: OCI update error: ' . $oci->get_error_string());
        $irss->error->_value = 'service_unavailable';
      }
      $irss->incrementRedirectStatStatus->_value = 'true';
    }
    if (DEBUG_ON) {
      var_dump($irss);
      var_dump($param);
    }

    return $ret;
  }


  /*******************************************************************************/


  /** \brief Checks if branch_id is part of agency_id
   *
   * return boolean
   */
  private function check_library_group($agency_id, $branch_id) {
    // simple case
    if ($agency_id == $branch_id
      || strpos($this->config->get_value('openagency_override', 'setup'), $agency_id) !== FALSE) {
      return TRUE;
    }

    // have to consult openagency
    if (empty($this->curl)) {
      $this->curl = new curl();
    }
    if (!$timeout = $this->config->get_value('openagency_timeout', 'setup'))
      $timeout = 20;
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);
    $agency_url = sprintf($this->config->get_value('openagency_url', 'setup'), $branch_id);
    $agency_list = $this->curl->get($agency_url);

    if ($this->curl->get_status('http_code') == 200) {
      $dom = new DomDocument();
      if (@ $dom->loadXML($agency_list)) {
        return ($agency_id == $dom->getElementsByTagName('agencyId')->item(0)->nodeValue);
      }
      else {
        verbose::log(FATAL, 'OpenOrder('.__LINE__.'):: Cannot parse result from openAgency. Request: ' . $agency_url);
      } 
    } 
    else {
      verbose::log(FATAL, 'OpenOrder('.__LINE__.'):: openagency http_error: ' . $this->curl->get_status('http_code'));
    } 
  
    return FALSE;

  }

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

  /** \brief Return the issn for a given pid - or error
   * 
   */
  private function pid_to_issn($pid) {
    $fname = TMP_PATH .  md5($responder_id . microtime(TRUE));
    $os_obj->pid = $pid;
    return $this->exec_order_policy($os_obj, $fname, 'pidToIssn');
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
  private function check_order_policy($record_id, $record_agency, $pids, $pickup_agency, $requester) {
    $os_obj->serviceRequester = $requester;
    $os_obj->bibliographicRecordId = $record_id;
    $os_obj->pickUpAgencyId = $pickup_agency;
    $os_obj->bibliographicRecordAgencyId = $record_agency;
    foreach ($pids as $pid) {
      if ($pid->_value) {
        $os_obj->pids[] = $pid->_value;
        $pid_str .= $pid->_value;
      }
    }
    $fname = TMP_PATH .  md5($record_id .  $record_agency . $pid_str . $pickup_agency .  $requester . microtime(TRUE));
    return $this->exec_order_policy($os_obj, $fname);
  }

  /** \brief wrapper for es xml update - send via z3950 or es Corba Bridge (Henry)
   *
   * return target_reference or FALSE
   */
  private function es_xmlupdate(&$ubf_xml, $need_answer=FALSE) {
    if (!$es_timeout = $this->config->get_value('es_timeout', 'setup')) {
      $es_timeout = 30;
    }
  // send thru a z3950 NEP
    if (($es_authentication = $this->config->get_value('es_authentication', 'setup')) &&
        ($es_target = $this->config->get_value('es_target', 'setup'))) {
      $this->watch->start('xml_update');
      $z3950 = new z3950();
      $z3950->set_authentication($es_authentication, $_SERVER['REMOTE_ADDR']);
      $z3950->set_target($es_target);
      $z_result = $z3950->z3950_xml_update($ubf_xml, $es_timeout);
      verbose::log(DEBUG, 'openorder:: ubf: ' . $ubf_xml);
      verbose::log(DEBUG, 'openorder:: result: ' . str_replace("\n", '', print_r($z_result, TRUE)));
// test
//          $z_result = Array ("xmlUpdateDoc" => '<ors:orderResponse xmlns:ors="http://oss.dbc.dk/ns/openresourcesharing"><ors:orderId>1000000068</ors:orderId></ors:orderResponse>');
      $this->watch->stop('xml_update');
      if ($z3950->get_errno()) {
        $this->error_string = $z3950->get_error_string();
        verbose::log(FATAL, 'openorder:: es_xmlupdate returned error: ' . $this->error_string);
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
    }
  // send thru a http-corba bridge
    if ($es_corba_bridge = $this->config->get_value('es_corba_bridge', 'setup')) {
      if (empty($this->curl)) {
        $this->curl = new curl();
      }
      $this->curl->set_option(CURLOPT_TIMEOUT, $es_timeout);
      $this->curl->set_post($ubf_xml);
      $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8'));
      $this->watch->start('xml_update (henry)');
      $f_result = $this->curl->get($es_corba_bridge);
      $this->watch->stop('xml_update (henry)');
      $curl_err = $this->curl->get_status();
      if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
        verbose::log(FATAL, 'es_coerba_bridge http-error: ' . $curl_err['http_code'] . ' from: ' . $es_corba_bridge);
        return ! $need_answer;
      }
      else {
          //var_dump($f_result);  die();
        if ($result = json_decode($f_result)) {
          //var_dump($result); die('aa');
          $resdom = new DomDocument();
          if (@ $resdom->loadXML($result->xml))
            if ($oid = $resdom->getElementsByTagName('orderId'))
              return $oid->item(0)->nodeValue;
        }
        else {
          verbose::log(FATAL, 'es_coerba_bridge: Cannot decode answer: ' . $f_result); 
        }
        return ! $need_answer;
      }
    }
    return FALSE;
  }

  /** \brief wrapper for exec of order policy-shell
   *
   * Use external order policy program to facilitate javascripts
   *
   * return error-array or false
   */
  private function exec_order_policy(&$os_obj, $fname, $par='') {
    $f_in = $fname . '.in';
    $f_out = $fname . '.out';
    if ($fp = fopen($f_in, 'w')) {
      fwrite($fp, json_encode($os_obj));
      fclose($fp);
      $es_status = exec(ORDER_POLICY_SHELL ." $f_in $f_out $par");
      unlink($f_in);
      if ($es_status)
        verbose::log(ERROR, ORDER_POLICY_SHELL . ' returned error-code: ' . $es_status);
      if (is_file($f_out)) {
        $es_answer = json_decode(file_get_contents($f_out));
        unlink($f_out);
        if ($es_answer) {
          $ret['lookUpUrl'] = $es_answer->lookupurl;
          $ret['lookUpUrls'] = $es_answer->lookupurls;
          $ret['agencyCatalogueUrl'] = $es_answer->agencyCatalogueUrl;
          $ret['agencyCatalogueUrls'] = $es_answer->agencyCatalogueUrls;
          $ret['orderPossible'] = ($this->xs_boolean($es_answer->willReceive) ? 'TRUE' : 'FALSE');
          $ret['orderPossibleReason'] = $es_answer->note;
          $ret['orderConditionDanish'] = $es_answer->conditionDanish;
          $ret['orderConditionEnglish'] = $es_answer->conditionEnglish;
          $ret['reason'] = $es_answer->reason;
        }
        else {
          verbose::log(ERROR, ORDER_POLICY_SHELL . ' could not decode answer in ' . $f_out);
          $ret['checkOrderPolicyError'] = 'service_unavailable';
        }
      }
      else {
        verbose::log(ERROR, ORDER_POLICY_SHELL . ' did not write an answer in ' . $f_out);
        $ret['checkOrderPolicyError'] = 'service_unavailable';
      }
    }
    else {
      verbose::log(ERROR, 'Could not open file ' . $f_in);
      $ret['checkOrderPolicyError'] = 'service_unavailable';
    }

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

  /** \brief
   *  return only digits and X
   */
  private function normalize_issn($issn) {
    return preg_replace('/[^0-9X]/', '', strtoupper($issn));
  }

}

/**
 *   MAIN
 */

$ws=new openOrder();
$ws->handle_request();

?>

