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
require_once("OLS_class_lib/oci_class.php");


class openOrder extends webServiceServer {

  public function __construct(){
    webServiceServer::__construct('openorder.ini');
  }

 /** \brief 
  *
  */
  function checkOrderPolicy($param) {
    var_dump($param); die();
  }


 /** \brief
  *
  */
  function placeOrder($param) {
    if ($this->check_order_policy($param->bibliographicRecordId->_value,
                                  $this->strip_agency($param->pickUpAgencyId->_value),
                                  $param->serviceRequester->_value)) {
      $ubf->author->_value = $param->author->_value;
      $ubf->authorOfComponent->_value = $param->authorOfComponent->_value;
      $ubf->bibliographicCategory->_value = $param->bibliographicCategory->_value;
      $ubf->bibliographicRecordAgencyId->_value = $param->bibliographicRecordAgencyId->_value;
      $ubf->bibliographicRecordId->_value = $param->bibliographicRecordId->_value;
      $ubf->callNumber->_value = $param->callNumber->_value;  // ??
      $ubf->copy->_value = $param->copy->_value;
      $ubf->edition->_value = $param->edition->_value;  // ??
      $ubf->exactEdition->_value = $param->exactEdition->_value;
      $ubf->isbn->_value = $param->isbn->_value;
      $ubf->issn->_value = $param->issn->_value;
      $ubf->issue->_value = $param->issue->_value;
      $ubf->itemId->_value = $param->itemId->_value;		// ??
      $ubf->language->_value = $param->language->_value;		// ??
      $ubf->localHoldingsId->_value = $param->localHoldingsId->_value;
      $ubf->mediumType->_value = $param->mediumType->_value;		// ??
      $ubf->needBeforeDate->_value = $param->needBeforeDate->_value;
      $ubf->orderId->_value = $param->orderId->_value;		// ??
      $ubf->orderSystem->_value = $param->orderSystem->_value;
      $ubf->pagination->_value = $param->pagination->_value;
      $ubf->pickUpAgencyId->_value = $param->pickUpAgencyId->_value;		// ??
      $ubf->placeOfPublication->_value = $param->placeOfPublication->_value;		// ??
      $ubf->publicationDate->_value = $param->publicationDate->_value;
      $ubf->publicationDateOfComponent->_value = $param->publicationDateOfComponent->_value;
      $ubf->publisher->_value = $param->publisher->_value;		// ??
      $ubf->seriesTitelNumber->_value = $param->seriesTitelNumber->_value;
      $ubf->serviceRequester->_value = $param->serviceRequester->_value;		// ??
      $ubf->title->_value = $param->title->_value;
      $ubf->titleOfComponent->_value = $param->titleOfComponent->_value;
      $ubf->userAddress->_value = $param->userAddress->_value;
      $ubf->userAgencyId->_value = $param->userAgencyId->_value;		// ??
      $ubf->userDateOfBirth->_value = $param->userDateOfBirth->_value;
      $ubf->userId->_value = $param->userId->_value;
      $ubf->userIdAuthenticated->_value = $param->userIdAuthenticated->_value;
      $ubf->userIdType->_value = $param->userIdType->_value;
      $ubf->userMail->_value = $param->userMail->_value;
      $ubf->userName->_value = $param->userName->_value;
      $ubf->userReferenceSource->_value = $param->userReferenceSource->_value;		// ??
      $ubf->userTelephone->_value = $param->userTelephone->_value;
      $ubf->verificationReferenceSource->_value = $param->verificationReferenceSource->_value;
      $ubf->volume->_value = $param->volume->_value;
    } else {
      $res->orderNotPlaced->_value->lookUpUrl->_value = "http://some.url";
      $res->orderNotPlaced->_value->placeOrderError->_value = "pickupAgency not found";
    }
    $ret->placeOrderResponse->_value = $res;
    var_dump($ret); var_dump($param); die();
  }


  private function check_order_policy($bib_id, $agency, $requester) {
    return TRUE;
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

