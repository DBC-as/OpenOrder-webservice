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
    var_dump($param); die();
  }


}

/**
 *   MAIN
 */

$ws=new openOrder();
$ws->handle_request();

?>

