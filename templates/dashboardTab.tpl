{* Native Website Settings tab for the Journal Payment dashboard. *}
<tab id="journalPayment" label="Pembayaran">
	<link rel="stylesheet" href="{$journalPaymentAssetUrl|escape}/styles/payment.css?v={$journalPaymentAssetVersion|escape}">
	<script src="{$journalPaymentAssetUrl|escape}/js/manage.js?v={$journalPaymentAssetVersion|escape}" defer></script>
	{capture assign=journalPaymentDashboardUrl}{url router=$smarty.const.ROUTE_COMPONENT component="plugins.generic.journalPayment.controllers.JournalPaymentDashboardHandler" op="fetch" filter=$journalPaymentFilter q=$journalPaymentQuery p=$journalPaymentPage view=$journalPaymentView driveFolder=$journalPaymentDriveFolder driveResult=$journalPaymentDriveResult financeResult=$journalPaymentFinanceResult productionResult=$journalPaymentProductionResult decisionError=$journalPaymentDecisionError documentMail=$journalPaymentDocumentMail recordResult=$journalPaymentRecordResult escape=false}{/capture}
	{load_url_in_div id="journalPaymentDashboardContainer" url=$journalPaymentDashboardUrl}
</tab>
