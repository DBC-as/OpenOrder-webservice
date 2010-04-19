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
 *
 */

require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/z3950_class.php");


class openOrder extends webServiceServer {

  public function __construct(){
    webServiceServer::__construct('openorder.ini');
    define("ESGAROTH_WRAPPER", $this->config->get_value("esgaroth_wrapper", "setup"));
    define("TMP_PATH", $this->config->get_value("tmp_path", "setup"));
  }

 /** \brief Check order policy for a given Agency
  * 
  * Request:
  * - serviceRequester
  * - bibliographicRecordId
  * - pickUpAgencyId
  *
  * Response:
  * - lookUpUrl
  * - orderPossible
  * - orderPossibleReason
  * or
  * - checkOrderPolicyError
  */
  public function checkOrderPolicy($param) {
    $copr = &$ret->checkOrderPolicyResponse->_value;
    if (!$this->aaa->has_right("openorder", 500))
      $copr->checkOrderPolicyError->_value = "authentication_error";
    elseif (empty($param->serviceRequester->_value))
      $copr->checkOrderPolicyError->_value = "serviceRequester is obligatory";
    else {
      $policy = $this->check_order_policy($param->bibliographicRecordId->_value,
                                          $this->strip_agency($param->pickUpAgencyId->_value),
                                          $param->serviceRequester->_value);
      if ($policy["checkOrderPolicyError"])
        $copr->checkOrderPolicyError->_value = $policy["checkOrderPolicyError"];
      else {
        $copr->lookUpUrl->_value = $policy["lookUpUrl"];
        $copr->orderPossible->_value = $policy["orderPossible"];
        $copr->orderPossibleReason->_value = $policy["orderPossibleReason"];
      }
    }

    //var_dump($copr); var_dump($param); die();

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
  */
  public function placeOrder($param) {
    $por = &$ret->placeOrderResponse->_value;
    if (!$this->aaa->has_right("openorder", 500))
      $por->orderNotPlaced->_value->placeOrderError->_value = "authentication_error";
    elseif (empty($param->serviceRequester->_value))
      $por->orderNotPlaced->_value->placeOrderError->_value = "serviceRequester is obligatory";
    else {
      $policy = $this->check_order_policy($param->bibliographicRecordId->_value,
                                          $this->strip_agency($param->pickUpAgencyId->_value),
                                          $param->serviceRequester->_value);
      if ($policy["checkOrderPolicyError"])
        $por->orderNotPlaced->_value->placeOrderError->_value = $policy["checkOrderPolicyError"];
      elseif ($policy["orderPossible"] != "TRUE") {
        $por->orderNotPlaced->_value->lookUpUrl->_value = $policy["lookUpUrl"];
        $por->orderNotPlaced->_value->placeOrderError->_value = $policy["orderPossibleReason"];
      } else {
        $ubf = new DOMDocument('1.0', 'utf-8');
        $order = $this->add_ubf_node($ubf, $ubf, "order", "", TRUE);
        $this->add_ubf_node($ubf, $order, "author", $param->author->_value);
        $this->add_ubf_node($ubf, $order, "authorOfComponent", $param->authorOfComponent->_value);
        $this->add_ubf_node($ubf, $order, "bibliographicCategory", $param->bibliographicCategory->_value);
        $this->add_ubf_node($ubf, $order, "bibliographicRecordAgencyId", $param->bibliographicRecordAgencyId->_value);
        $this->add_ubf_node($ubf, $order, "bibliographicRecordId", $param->bibliographicRecordId->_value);
        $this->add_ubf_node($ubf, $order, "callNumber", $param->callNumber->_value);  // ??
        $this->add_ubf_node($ubf, $order, "copy", $param->copy->_value);
        $this->add_ubf_node($ubf, $order, "edition", $param->edition->_value);  // ??
        $this->add_ubf_node($ubf, $order, "exactEdition", $param->exactEdition->_value);
        $this->add_ubf_node($ubf, $order, "isbn", $param->isbn->_value);
        $this->add_ubf_node($ubf, $order, "issn", $param->issn->_value);
        $this->add_ubf_node($ubf, $order, "issue", $param->issue->_value);
        $this->add_ubf_node($ubf, $order, "itemId", $param->itemId->_value);		// ??
        $this->add_ubf_node($ubf, $order, "language", $param->language->_value);		// ??
        $this->add_ubf_node($ubf, $order, "localHoldingsId", $param->localHoldingsId->_value);
        $this->add_ubf_node($ubf, $order, "mediumType", $param->mediumType->_value);		// ??
        $this->add_ubf_node($ubf, $order, "needBeforeDate", $param->needBeforeDate->_value);
        $this->add_ubf_node($ubf, $order, "orderId", $param->orderId->_value);		// ??
        $this->add_ubf_node($ubf, $order, "orderSystem", $param->orderSystem->_value);
        $this->add_ubf_node($ubf, $order, "pagination", $param->pagination->_value);
        $this->add_ubf_node($ubf, $order, "pickUpAgencyId", $param->pickUpAgencyId->_value);		// ??
        $this->add_ubf_node($ubf, $order, "placeOfPublication", $param->placeOfPublication->_value);		// ??
        $this->add_ubf_node($ubf, $order, "publicationDate", $param->publicationDate->_value);
        $this->add_ubf_node($ubf, $order, "publicationDateOfComponent", $param->publicationDateOfComponent->_value);
        $this->add_ubf_node($ubf, $order, "publisher", $param->publisher->_value);		// ??
        $this->add_ubf_node($ubf, $order, "seriesTitelNumber", $param->seriesTitelNumber->_value);
        $this->add_ubf_node($ubf, $order, "title", $param->title->_value);
        $this->add_ubf_node($ubf, $order, "titleOfComponent", $param->titleOfComponent->_value);
        $this->add_ubf_node($ubf, $order, "userAddress", $param->userAddress->_value);
        $this->add_ubf_node($ubf, $order, "userAgencyId", $param->userAgencyId->_value);		// ??
        $this->add_ubf_node($ubf, $order, "userDateOfBirth", $param->userDateOfBirth->_value);
        $this->add_ubf_node($ubf, $order, "userId", $param->userId->_value);
        if ($param->userId->_value)
          $this->add_ubf_node($ubf, $order, "userIdAuthenticated", $this->xs_boolean($param->userIdAuthenticated->_value) ? "yes" : "no");
        $this->add_ubf_node($ubf, $order, "userIdType", $param->userIdType->_value);
        $this->add_ubf_node($ubf, $order, "userMail", $param->userMail->_value);
        $this->add_ubf_node($ubf, $order, "userName", $param->userName->_value);
        $this->add_ubf_node($ubf, $order, "userReferenceSource", $param->userReferenceSource->_value);		// ??
        $this->add_ubf_node($ubf, $order, "userTelephone", $param->userTelephone->_value);
        $this->add_ubf_node($ubf, $order, "verificationReferenceSource", $param->verificationReferenceSource->_value);
        $this->add_ubf_node($ubf, $order, "volume", $param->volume->_value);
  
        $ubf_xml = $ubf->saveXML();
        //echo "ubf: <pre>" . $ubf_xml . "</pre>\n";
        if ($this->validate["ubf"] && !$this->validate_xml($ubf_xml, $this->validate["ubf"])) {
            $por->orderNotPlaced->_value->lookUpUrl->_value = $policy["lookUpUrl"];
            $por->orderNotPlaced->_value->placeOrderError->_value = "Order does not validate";
        } else {
    // send ubf-itemorder via z3950
          $this->watch->start("xml_update");
          $z3950 = new z3950();
          $z3950->set_authentication($this->config->get_value("es_authentication", "setup"), $_SERVER["REMOTE_ADDR"]);
          $z3950->set_target($this->config->get_value("es_target", "setup"));
          $tgt_ref = $z3950->z3950_xml_itemorder($ubf_xml, $this->config->get_value("es_timeout", "setup"));
          $this->watch->stop("xml_update");
          if ($tgt_ref = $tgt_ref['targetReference']) {
            $por->orderPlaced->_value->orderId->_value = $tgt_ref;
            $por->orderPlaced->_value->orderPlacesMessage->_value = "item available at pickupAgency, order accepted";
          } else {
            verbose::log(ERROR, "openorder:: xml_itemorder status: " . $z3950->get_error_string());
            $por->orderNotPlaced->_value->lookUpUrl->_value = $policy["lookUpUrl"];
            $por->orderNotPlaced->_value->placeOrderError->_value = "Error sending order to ORS";
          }
          //var_dump($tgt_ref);
          //var_dump($z3950->get_error());
        }
  
  
      }
    }
    //var_dump($ret); var_dump($param); die();
    return $ret;
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

 /** \brief Check order policy for a given Agency
  * 
  * Use external esgaroth program to facilitate javascripts
  *
  * return error-array or false
  */
  private function check_order_policy($bib_id, $agency, $requester) {
    $fname = TMP_PATH .  md5($bib_id .  $agency .  $requester);
    $f_in = $fname . '.in';
    $f_out = $fname . '.out';
    $os_obj->serviceRequester = $requester;
    $os_obj->bibliographicRecordId = $bib_id;
    $os_obj->pickUpAgencyId = $agency;
    if ($fp = fopen($f_in, "w")) {
      fwrite($fp, json_encode($os_obj));
      fclose($fp);
      $es_status = exec(ESGAROTH_WRAPPER ." $f_in $f_out");
      unlink($f_in);
      if ($es_status)
        verbose::log(ERROR, ESGAROTH_WRAPPER . " returned error-code: " . $es_status);
      if (is_file($f_out)) {
        $es_answer = json_decode(file_get_contents($f_out));
        unlink($f_out);
        if ($es_answer) {
          $ret["lookUpUrl"] = $es_answer->lookupUrl;
          $ret["orderPossible"] = ($es_answer->willReceive == "true" ? "TRUE" : "FALSE");
          $ret["orderPossibleReason"] = $es_answer->note;
        } else
          $ret["checkOrderPolicyError"] = "service unavailable";
      } else {
        $ret["checkOrderPolicyError"] = "service unavailable";
        verbose::log(ERROR, ESGAROTH_WRAPPER . " did not write an answer in " . $f_out);
      }
    } else
      $ret["checkOrderPolicyError"] = "service unavailable";

    //var_dump($es_answer); 
    return $ret;
  }


 /** \brief
  *  return true if xs:boolean is so
  */
  private function xs_boolean($str) {
    return (strtolower($str) == "true" || $str == 1);
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

