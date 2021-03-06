<?php 
define("ADMINAREA", true);
require("../init.php");
$aInt = new WHMCS\Admin("List Invoices", false);
$aInt->requiredFiles(array( "gatewayfunctions", "invoicefunctions", "processinvoices" ));
$aInt->setClientsProfilePresets();
if( $delete || $massdelete ) 
{
    checkPermission("Delete Invoice");
}

if( $markpaid || $markunpaid || $markcancelled ) 
{
    checkPermission("Manage Invoice");
}

$userId = $aInt->valUserID($whmcs->get_req_var("userid"));
$aInt->assertClientBoundary($userid);
if( $markpaid ) 
{
    check_token("WHMCS.admin.default");
    $failedInvoices = array(  );
    $invoiceCount = 0;
    foreach( $selectedinvoices as $invid ) 
    {
        if( get_query_val("tblinvoices", "status", array( "id" => $invid )) == "Paid" ) 
        {
            continue;
        }

        $paymentMethod = get_query_val("tblinvoices", "paymentmethod", array( "id" => $invid ));
        if( addInvoicePayment($invid, "", "", "", $paymentMethod) === false ) 
        {
            $failedInvoices[] = $invid;
        }

        $invoiceCount++;
    }
    if( 0 < count($selectedinvoices) ) 
    {
        $failedInvoices["successfulInvoicesCount"] = $invoiceCount - count($failedInvoices);
        WHMCS\Cookie::set("FailedMarkPaidInvoices", $failedInvoices);
    }

    if( $page ) 
    {
        $userid .= "&page=" . $page;
    }

    redir("userid=" . $userid . "&filter=1");
}

if( $markunpaid ) 
{
    check_token("WHMCS.admin.default");
    foreach( $selectedinvoices as $invid ) 
    {
        update_query("tblinvoices", array( "status" => "Unpaid", "datepaid" => "0000-00-00 00:00:00" ), array( "id" => $invid ));
        logActivity("Reactivated Invoice - Invoice ID: " . $invid, $userid);
        run_hook("InvoiceUnpaid", array( "invoiceid" => $invid ));
    }
    if( $page ) 
    {
        $userid .= "&page=" . $page;
    }

    redir("userid=" . $userid . "&filter=1");
}

if( $markcancelled ) 
{
    check_token("WHMCS.admin.default");
    foreach( $selectedinvoices as $invid ) 
    {
        update_query("tblinvoices", array( "status" => "Cancelled" ), array( "id" => $invid ));
        logActivity("Cancelled Invoice - Invoice ID: " . $invid, $userid);
        run_hook("InvoiceCancelled", array( "invoiceid" => $invid ));
    }
    if( $page ) 
    {
        $userid .= "&page=" . $page;
    }

    redir("userid=" . $userid . "&filter=1");
}

if( $duplicateinvoice ) 
{
    check_token("WHMCS.admin.default");
    foreach( $selectedinvoices as $invid ) 
    {
        $invoices = new WHMCS\Invoices();
        $invoices->duplicate($invid);
    }
    if( $page ) 
    {
        $userid .= "&page=" . $page;
    }

    redir("userid=" . $userid . "&filter=1");
}

if( $massdelete ) 
{
    check_token("WHMCS.admin.default");
    foreach( $selectedinvoices as $invoiceId ) 
    {
        $invoice = WHMCS\User\Client::find($userId)->invoices->find($invoiceId);
        if( $invoice ) 
        {
            $invoice->delete();
            logActivity("Deleted Invoice - Invoice ID: " . $invoiceId, $userId);
        }

    }
    if( $page ) 
    {
        $userId .= "&page=" . $page;
    }

    redir("userid=" . $userId . "&filter=1");
}

if( $paymentreminder ) 
{
    check_token("WHMCS.admin.default");
    foreach( $selectedinvoices as $invid ) 
    {
        sendMessage("Invoice Payment Reminder", $invid);
        logActivity("Invoice Payment Reminder Sent - Invoice ID: " . $invid, $userid);
    }
    if( $page ) 
    {
        $userid .= "&page=" . $page;
    }

    redir("userid=" . $userid . "&filter=1");
}

if( $merge ) 
{
    check_token("WHMCS.admin.default");
    checkPermission("Manage Invoice");
    if( count($selectedinvoices) < 2 ) 
    {
        if( $page ) 
        {
            $userid .= "&page=" . $page;
        }

        redir("userid=" . $userid . "&mergeerr=1");
    }

    $selectedinvoices = db_escape_numarray($selectedinvoices);
    sort($selectedinvoices);
    $endinvoiceid = end($selectedinvoices);
    update_query("tblinvoiceitems", array( "invoiceid" => $endinvoiceid ), "invoiceid IN (" . db_build_in_array($selectedinvoices) . ")");
    update_query("tblaccounts", array( "invoiceid" => $endinvoiceid ), "invoiceid IN (" . db_build_in_array($selectedinvoices) . ")");
    update_query("tblorders", array( "invoiceid" => $endinvoiceid ), "invoiceid IN (" . db_build_in_array($selectedinvoices) . ")");
    $result = select_query("tblinvoices", "SUM(credit)", "id IN (" . db_build_in_array($selectedinvoices) . ")");
    $data = mysql_fetch_array($result);
    $totalcredit = $data[0];
    update_query("tblinvoices", array( "credit" => $totalcredit ), array( "id" => $endinvoiceid ));
    unset($selectedinvoices[count($selectedinvoices) - 1]);
    delete_query("tblinvoices", "id IN (" . db_build_in_array($selectedinvoices) . ")");
    updateInvoiceTotal($endinvoiceid);
    logActivity("Merged Invoice IDs " . db_build_in_array($selectedinvoices) . " to Invoice ID: " . $endinvoiceid, $userid);
    if( $page ) 
    {
        $userid .= "&page=" . $page;
    }

    redir("userid=" . $userid . "&filter=1");
}

if( $masspay ) 
{
    check_token("WHMCS.admin.default");
    if( count($selectedinvoices) < 2 ) 
    {
        if( $page ) 
        {
            $userid .= "&page=" . $page;
        }

        redir("userid=" . $userid . "&masspayerr=1");
    }

    $invoiceid = createInvoices($userid);
    $paymentmethod = getClientsPaymentMethod($userid);
    $invoiceitems = array(  );
    foreach( $selectedinvoices as $invoiceid ) 
    {
        $result = select_query("tblinvoices", "", array( "id" => $invoiceid ));
        $data = mysql_fetch_array($result);
        $subtotal += $data["subtotal"];
        $credit += $data["credit"];
        $tax += $data["tax"];
        $tax2 += $data["tax2"];
        $thistotal = $data["total"];
        $result = select_query("tblaccounts", "SUM(amountin)", array( "invoiceid" => $invoiceid ));
        $data = mysql_fetch_array($result);
        $thispayments = $data[0];
        $thistotal = $thistotal - $thispayments;
        insert_query("tblinvoiceitems", array( "userid" => $userid, "type" => "Invoice", "relid" => $invoiceid, "description" => $_LANG["invoicenumber"] . $invoiceid, "amount" => $thistotal, "duedate" => "now()", "paymentmethod" => $paymentmethod ));
    }
    $invoiceid = createInvoices($userid, true, true, array( "invoices" => $selectedinvoices ));
    redir("userid=" . $userid . "&masspayid=" . $invoiceid . "&filter=1");
}

if( $delete ) 
{
    check_token("WHMCS.admin.default");
    checkPermission("Delete Invoice");
    $invoiceID = (int) $whmcs->get_req_var("invoiceid");
    $invoice = WHMCS\User\Client::find($userId)->invoices->find($invoiceID);
    if( $invoice ) 
    {
        if( $whmcs->get_req_var("returnCredit") ) 
        {
            removeCreditOnInvoiceDelete($invoiceID);
        }

        $invoice->delete();
        logActivity("Deleted Invoice - Invoice ID: " . $invoiceID, $userId);
    }

    if( $page ) 
    {
        $userId .= "&page=" . $page;
    }

    redir("userid=" . $userId . "&filter=1");
}

ob_start();
$jquerycode .= "\$(\".invtooltip\").tooltip({cssClass:\"invoicetooltip\"});";
$jsCode = "";
if( $mergeerr ) 
{
    infoBox($aInt->lang("invoices", "mergeerror"), $aInt->lang("invoices", "mergeerrordesc"));
}

if( $masspayerr ) 
{
    infoBox($aInt->lang("invoices", "masspay"), $aInt->lang("invoices", "mergeerrordesc"));
}

if( $masspayid ) 
{
    infoBox($aInt->lang("invoices", "masspay"), $aInt->lang("invoices", "masspaysuccess") . " - <a href=\"invoices.php?action=edit&id=" . (int) $masspayid . "\">" . $aInt->lang("fields", "invoicenum") . $masspayid . "</a>");
}

echo $infobox;
$filt = new WHMCS\Filter("clinv");
$filterops = array( "serviceid", "addonid", "domainid", "clientname", "invoicenum", "lineitem", "paymentmethod", "invoicedate", "duedate", "datepaid", "totalfrom" . "totalto", "status" );
$filt->setAllowedVars($filterops);
$filters = array(  );
$filters[] = "userid='" . (int) $userid . "'";
if( $serviceid = $filt->get("serviceid") ) 
{
    $filters[] = "id IN (SELECT invoiceid FROM tblinvoiceitems WHERE type='Hosting' AND relid='" . (int) $serviceid . "')";
}

if( $addonid = $filt->get("addonid") ) 
{
    $filters[] = "id IN (SELECT invoiceid FROM tblinvoiceitems WHERE type='Addon' AND relid='" . (int) $addonid . "')";
}

if( $domainid = $filt->get("domainid") ) 
{
    $filters[] = "id IN (SELECT invoiceid FROM tblinvoiceitems WHERE type IN ('DomainRegister','DomainTransfer','Domain') AND relid='" . (int) $domainid . "')";
}

if( $clientname = $filt->get("clientname") ) 
{
    $filters[] = "concat(firstname,' ',lastname) LIKE '%" . db_escape_string($clientname) . "%'";
}

if( $invoicenum = $filt->get("invoicenum") ) 
{
    $filters[] = "(tblinvoices.id='" . db_escape_string($invoicenum) . "' OR tblinvoices.invoicenum='" . db_escape_string($invoicenum) . "')";
}

if( $lineitem = $filt->get("lineitem") ) 
{
    $filters[] = "tblinvoices.id IN (SELECT invoiceid FROM tblinvoiceitems WHERE userid=" . (int) $userid . " AND description LIKE '%" . db_escape_string($lineitem) . "%')";
}

if( $paymentmethod = $filt->get("paymentmethod") ) 
{
    $filters[] = "tblinvoices.paymentmethod='" . db_escape_string($paymentmethod) . "'";
}

if( $invoicedate = $filt->get("invoicedate") ) 
{
    $filters[] = "tblinvoices.date='" . toMySQLDate($invoicedate) . "'";
}

if( $duedate = $filt->get("duedate") ) 
{
    $filters[] = "tblinvoices.duedate='" . toMySQLDate($duedate) . "'";
}

if( $datepaid = $filt->get("datepaid") ) 
{
    $filters[] = "tblinvoices.datepaid>='" . toMySQLDate($datepaid) . "' AND tblinvoices.datepaid<='" . toMySQLDate($datepaid) . " 23:59:59'";
}

if( $totalfrom = $filt->get("totalfrom") ) 
{
    $filters[] = "tblinvoices.total>='" . db_escape_string($totalfrom) . "'";
}

if( $totalto = $filt->get("totalto") ) 
{
    $filters[] = "tblinvoices.total<='" . db_escape_string($totalto) . "'";
}

if( $status = $filt->get("status") ) 
{
    if( $status == "Overdue" ) 
    {
        $filters[] = "tblinvoices.status='Unpaid' AND tblinvoices.duedate<'" . date("Ymd") . "'";
    }
    else
    {
        $filters[] = "tblinvoices.status='" . db_escape_string($status) . "'";
    }

}

$filt->store();
WHMCS\Session::release();
$failedInvoices = WHMCS\Input\Sanitize::makeSafeForOutput(WHMCS\Cookie::get("FailedMarkPaidInvoices", true));
$successfulInvoicesCount = (int) $failedInvoices["successfulInvoicesCount"];
unset($failedInvoices["successfulInvoicesCount"]);
WHMCS\Cookie::delete("FailedMarkPaidInvoices");
if( 0 < $successfulInvoicesCount || 0 < count($failedInvoices) ) 
{
    $description = sprintf($aInt->lang("invoices", "markPaidSuccess"), $successfulInvoicesCount);
    if( 0 < count($failedInvoices) ) 
    {
        $failedInvoicesString = (string) implode(", ", $failedInvoices);
        $description .= "<br />" . sprintf($aInt->lang("invoices", "markPaidError"), $failedInvoicesString);
        $description .= "<br />" . $aInt->lang("invoices", "markPaidErrorInfo") . " <a href=\"http://docs.whmcs.com/Clients:Invoices_Tab#Mark_Paid\" target=\"_blank\">" . $aInt->lang("global", "findoutmore") . "</a>";
    }

    $infoBoxTitle = $aInt->lang("global", "successWithErrors");
    $infoBoxType = "info";
    if( count($failedInvoices) == 0 ) 
    {
        $infoBoxTitle = $aInt->lang("global", "success");
        $infoBoxType = "success";
    }

    if( $successfulInvoicesCount == 0 ) 
    {
        $infoBoxTitle = $aInt->lang("global", "erroroccurred");
        $infoBoxType = "error";
    }

    infoBox($infoBoxTitle, $description, $infoBoxType);
    echo $infobox;
}

echo WHMCS\View\Asset::jsInclude("jquerytt.js");
echo "\n<form action=\"";
echo $whmcs->getPhpSelf();
echo "?userid=";
echo $userid;
echo "\" method=\"post\">\n\n<div class=\"context-btn-container\">\n    <button type=\"submit\" class=\"btn btn-default\">\n        <i class=\"fa fa-search\"></i>\n        ";
echo $aInt->lang("global", "search");
echo "    </button>\n    <button type=\"button\" class=\"btn btn-primary\" onClick=\"window.location='invoices.php?action=createinvoice&userid=";
echo $userid . generate_token("link");
echo "'\" class=\"btn-success\">\n        <i class=\"fa fa-plus\"></i>\n        ";
echo $aInt->lang("invoices", "create");
echo "    </button>\n</div>\n\n<table class=\"form\" width=\"100%\" border=\"0\" cellspacing=\"2\" cellpadding=\"3\">\n\n<tr><td width=\"15%\" class=\"fieldlabel\">";
echo $aInt->lang("fields", "invoicenum");
echo "</td><td class=\"fieldarea\"><input type=\"text\" name=\"invoicenum\" class=\"form-control input-150\" value=\"";
echo $invoicenum;
echo "\"></td><td width=\"15%\" class=\"fieldlabel\">";
echo $aInt->lang("fields", "invoicedate");
echo "</td><td class=\"fieldarea\"><input type=\"text\" name=\"invoicedate\" value=\"";
echo $invoicedate;
echo "\" class=\"form-control date-picker\"></td></tr>\n<tr><td class=\"fieldlabel\">";
echo $aInt->lang("fields", "lineitem");
echo "</td><td class=\"fieldarea\"><input type=\"text\" name=\"lineitem\" class=\"form-control input-300\" value=\"";
echo $lineitem;
echo "\"></td><td width=\"15%\" class=\"fieldlabel\">";
echo $aInt->lang("fields", "duedate");
echo "</td><td class=\"fieldarea\"><input type=\"text\" name=\"duedate\" size=\"15\" value=\"";
echo $duedate;
echo "\" class=\"form-control date-picker\"></td></tr>\n<tr><td class=\"fieldlabel\">";
echo $aInt->lang("fields", "paymentmethod");
echo "</td><td class=\"fieldarea\">";
echo paymentMethodsSelection($aInt->lang("global", "any"));
echo "</td><td width=\"15%\" class=\"fieldlabel\">";
echo $aInt->lang("fields", "datepaid");
echo "</td><td class=\"fieldarea\"><input type=\"text\" name=\"datepaid\" size=\"15\" value=\"";
echo $datepaid;
echo "\" class=\"form-control date-picker\"></td></tr>\n<tr><td class=\"fieldlabel\">";
echo $aInt->lang("fields", "status");
echo "</td><td class=\"fieldarea\"><select name=\"status\" class=\"form-control select-inline\">\n    <option value=\"\">";
echo $aInt->lang("global", "any");
echo "</option>\n    <option value=\"Draft\"";
if( $status == "Draft" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "draft");
echo "</option>\n    <option value=\"Unpaid\"";
if( $status == "Unpaid" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "unpaid");
echo "</option>\n    <option value=\"Overdue\"";
if( $status == "Overdue" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "overdue");
echo "</option>\n    <option value=\"Paid\"";
if( $status == "Paid" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "paid");
echo "</option>\n    <option value=\"Cancelled\"";
if( $status == "Cancelled" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "cancelled");
echo "</option>\n    <option value=\"Refunded\"";
if( $status == "Refunded" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "refunded");
echo "</option>\n    <option value=\"Collections\"";
if( $status == "Collections" ) 
{
    echo " selected";
}

echo ">";
echo $aInt->lang("status", "collections");
echo "</option>\n    <option value=\"Payment Pending\"";
if( $status == "Payment Pending" ) 
{
    echo " selected";
}

echo ">";
echo AdminLang::trans("status.paymentpending");
echo "</option>\n</select></td><td class=\"fieldlabel\">";
echo $aInt->lang("fields", "totaldue");
echo "</td><td class=\"fieldarea\">";
echo $aInt->lang("filters", "from");
echo " <input type=\"text\" name=\"totalfrom\" class=\"form-control input-100 input-inline\" value=\"";
echo $totalfrom;
echo "\"> ";
echo $aInt->lang("filters", "to");
echo " <input type=\"text\" name=\"totalto\" class=\"form-control input-100 input-inline\" value=\"";
echo $totalto;
echo "\"></td></tr>\n</table>\n\n</form>\n\n<br />\n\n";
$currency = getCurrency($userid);
$gatewaysarray = getGatewaysArray();
$aInt->sortableTableInit("duedate", "DESC");
$result = select_query("tblinvoices", "COUNT(*)", implode(" AND ", $filters));
$data = mysql_fetch_array($result);
$numrows = $data[0];
$qryorderby = $orderby;
if( $qryorderby == "id" ) 
{
    $qryorderby = "tblinvoices`.`invoicenum` " . $order . ",`tblinvoices`.`id";
}

$result = select_query("tblinvoices", "", implode(" AND ", $filters), $qryorderby, $order, $page * $limit . "," . $limit);
while( $data = mysql_fetch_array($result) ) 
{
    $id = $data["id"];
    $invoicenum = $data["invoicenum"];
    $date = $data["date"];
    $duedate = $data["duedate"];
    $datepaid = $data["datepaid"];
    $credit = $data["credit"];
    $total = $data["total"];
    $paymentmethod = $data["paymentmethod"];
    $paymentmethod = $gatewaysarray[$paymentmethod];
    $status = $data["status"];
    $status = getInvoiceStatusColour($status, false);
    $date = fromMySQLDate($date);
    $duedate = fromMySQLDate($duedate);
    $datepaid = ($datepaid == "0000-00-00 00:00:00" ? "-" : fromMySQLDate($datepaid));
    $total = formatCurrency($credit + $total);
    if( !$invoicenum ) 
    {
        $invoicenum = $id;
    }

    $payments = Illuminate\Database\Capsule\Manager::table("tblaccounts")->where("invoiceid", "=", $invoice["id"])->count("id");
    $deleteLink = "<a href=\"#\" onClick=\"doDelete('" . $id . "');return false\">\n    <img src=\"images/delete.gif\" width=\"16\" height=\"16\" border=\"0\" alt=\"" . $aInt->lang("global", "delete") . "\">\n</a>";
    if( 0 < $credit && 0 < $payments ) 
    {
        $deleteLink = "<a href=\"#\" onclick=\"openModal('ExistingCreditAndPayments', " . $id . ")\">\n    <img src=\"images/delete.gif\" width=\"16\" height=\"16\" border=\"0\" alt=\"" . $aInt->lang("global", "delete") . "\">\n</a>";
    }
    else
    {
        if( 0 < $credit && $payments == 0 ) 
        {
            $deleteLink = "<a href=\"#\" onclick=\"openModal('ExistingCredit', " . $id . ")\">\n    <img src=\"images/delete.gif\" width=\"16\" height=\"16\" border=\"0\" alt=\"" . $aInt->lang("global", "delete") . "\">\n</a>";
        }
        else
        {
            if( $credit == 0 && 0 < $payments ) 
            {
                $deleteLink = "<a href=\"#\" onclick=\"openModal('ExistingPayments', " . $id . ")\">\n    <img src=\"images/delete.gif\" width=\"16\" height=\"16\" border=\"0\" alt=\"" . $aInt->lang("global", "delete") . "\">\n</a>";
            }

        }

    }

    $tabledata[] = array( "<input type=\"checkbox\" name=\"selectedinvoices[]\" value=\"" . $id . "\" class=\"checkall\">", "<a href=\"invoices.php?action=edit&id=" . $id . "\">" . $invoicenum . "</a>", $date, $duedate, $datepaid, "<a href=\"invoices.php?action=invtooltip&id=" . $id . "&userid=" . $userid . generate_token("link") . "\" class=\"invtooltip\" lang=\"\">" . $total . "</a>", $paymentmethod, $status, "<a href=\"invoices.php?action=edit&id=" . $id . "\"><img src=\"images/edit.gif\" width=\"16\" height=\"16\" border=\"0\" alt=\"" . $aInt->lang("global", "edit") . "\"></a>", $deleteLink );
}
$tableformurl = $_SERVER["PHP_SELF"] . "?userid=" . $userid . "&filter=1";
if( $page ) 
{
    $tableformurl .= "&page=" . $page;
}

$tableformbuttons = "<input type=\"submit\" value=\"" . $aInt->lang("invoices", "markpaid") . "\" class=\"btn btn-success\" name=\"markpaid\" onclick=\"return confirm('" . $aInt->lang("invoices", "markpaidconfirm", "1") . "')\" /> <input type=\"submit\" value=\"" . $aInt->lang("invoices", "markunpaid") . "\" class=\"btn btn-default\" name=\"markunpaid\" onclick=\"return confirm('" . $aInt->lang("invoices", "markunpaidconfirm", "1") . "')\" /> <input type=\"submit\" value=\"" . $aInt->lang("invoices", "markcancelled") . "\" class=\"btn btn-default\" name=\"markcancelled\" onclick=\"return confirm('" . $aInt->lang("invoices", "markcancelledconfirm", "1") . "')\" /> <input type=\"submit\" value=\"" . $aInt->lang("invoices", "duplicateinvoice") . "\" class=\"btn btn-default\" name=\"duplicateinvoice\" onclick=\"return confirm('" . $aInt->lang("invoices", "duplicateinvoiceconfirm", "1") . "')\" />   <input type=\"submit\" value=\"" . $aInt->lang("invoices", "sendreminder") . "\" class=\"btn btn-default\" name=\"paymentreminder\" onclick=\"return confirm('" . $aInt->lang("invoices", "sendreminderconfirm", "1") . "')\" /> <input type=\"submit\" value=\"" . $aInt->lang("invoices", "merge") . "\" class=\"btn btn-default\" name=\"merge\" onclick=\"return confirm('" . $aInt->lang("invoices", "mergeconfirm", "1") . "')\" /> <input type=\"submit\" value=\"" . $aInt->lang("invoices", "masspay") . "\" class=\"btn btn-default\" name=\"masspay\" onclick=\"return confirm('" . $aInt->lang("invoices", "masspayconfirm", "1") . "')\" /> <input type=\"submit\" value=\"" . $aInt->lang("global", "delete") . "\" class=\"btn btn-danger\" name=\"massdelete\" onclick=\"return confirm('" . $aInt->lang("invoices", "massdeleteconfirm", "1") . "')\" />";
echo $aInt->sortableTable(array( "checkall", array( "id", $aInt->lang("fields", "invoicenum") ), array( "date", $aInt->lang("fields", "invoicedate") ), array( "duedate", $aInt->lang("fields", "duedate") ), array( "datepaid", $aInt->lang("fields", "datepaid") ), array( "total", $aInt->lang("fields", "total") ), array( "paymentmethod", $aInt->lang("fields", "paymentmethod") ), array( "status", $aInt->lang("fields", "status") ), "", "" ), $tabledata, $tableformurl, $tableformbuttons);
echo $aInt->modal("ExistingCreditAndPayments", $aInt->lang("invoices", "existingCreditTitle"), $aInt->lang("invoices", "existingCredit"), array( array( "title" => $aInt->lang("invoices", "existingCreditReturn"), "onclick" => "\$(\"#existingPaymentsReturnCredit\").modal(\"show\")" ), array( "title" => $aInt->lang("invoices", "existingCreditDiscard"), "onclick" => "\$(\"#existingPaymentsDiscardCredit\").modal(\"show\");" ), array( "title" => $aInt->lang("global", "cancel") ) ));
echo $aInt->modal("ExistingPaymentsReturnCredit", $aInt->lang("invoices", "existingPaymentsTitle"), $aInt->lang("invoices", "existingPayments"), array( array( "title" => $aInt->lang("invoices", "existingPaymentsOrphan"), "onclick" => "doDeleteCall(\"returnCredit\");" ), array( "title" => $aInt->lang("global", "no") ) ));
echo $aInt->modal("ExistingPaymentsDiscardCredit", $aInt->lang("invoices", "existingPaymentsTitle"), $aInt->lang("invoices", "existingPayments"), array( array( "title" => $aInt->lang("invoices", "existingPaymentsOrphan"), "onclick" => "doDeleteCall()" ), array( "title" => $aInt->lang("global", "no") ) ));
echo $aInt->modal("ExistingCredit", $aInt->lang("invoices", "existingCreditTitle"), $aInt->lang("invoices", "existingCredit"), array( array( "title" => $aInt->lang("invoices", "existingCreditReturn"), "onclick" => "doDeleteCall(\"returnCredit\")" ), array( "title" => $aInt->lang("invoices", "existingCreditDiscard"), "onclick" => "doDeleteCall()" ), array( "title" => $aInt->lang("global", "cancel") ) ));
echo $aInt->modal("ExistingPayments", $aInt->lang("invoices", "existingPaymentsTitle"), $aInt->lang("invoices", "existingPayments"), array( array( "title" => $aInt->lang("invoices", "existingPaymentsOrphan"), "onclick" => "doDeleteCall()" ), array( "title" => $aInt->lang("global", "no") ) ));
$jsCode = "var invoice = 0;\nfunction openModal(displayModal, invoiceID) {\n    /**\n     * Store the invoiceID in the global JS variable\n     */\n    invoice = invoiceID;\n    \$('#modal' + displayModal).modal('show');\n}\n\nfunction doDeleteCall(credit) {\n    if (credit == 'returnCredit') {\n        doDeleteReturnCredit(invoice);\n    } else {\n        doDelete(invoice);\n    }\n}";
echo $aInt->modalWithConfirmation("doDelete", $aInt->lang("invoices", "delete"), $whmcs->getPhpSelf() . "?userid=" . $userid . "&delete=true&invoiceid=");
echo $aInt->modalWithConfirmation("doDeleteReturnCredit", $aInt->lang("invoices", "delete"), $whmcs->getPhpSelf() . "?userid=" . $userid . "&delete=true&returnCredit=true&invoiceid=");
$content = ob_get_contents();
ob_end_clean();
$aInt->content = $content;
$aInt->jquerycode = $jquerycode;
$aInt->jscode = $jsCode;
$aInt->display();

