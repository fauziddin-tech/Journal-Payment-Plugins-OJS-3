{* Native Website Settings tab for the Journal Payment dashboard (OJS 3.5).
   payment.css and manage.js are registered on this page by the plugin's
   TemplateManager::display hook because Vue 3 drops <script> tags here. *}
<tab id="journalPayment" label="Pembayaran">
	{capture assign=journalPaymentDashboardUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="plugins.generic.journalPayment.controllers.JournalPaymentDashboardHandler" op="fetch" filter=$journalPaymentFilter q=$journalPaymentQuery p=$journalPaymentPage view=$journalPaymentView driveFolder=$journalPaymentDriveFolder driveResult=$journalPaymentDriveResult financeResult=$journalPaymentFinanceResult productionResult=$journalPaymentProductionResult decisionError=$journalPaymentDecisionError documentMail=$journalPaymentDocumentMail recordResult=$journalPaymentRecordResult escape=false}{/capture}
	{load_url_in_div id="journalPaymentDashboardContainer" url=$journalPaymentDashboardUrl}
</tab>
