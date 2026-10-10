# Scout Accounting Toolkit (SAT)

I'm using AI to develop tools to track and report on income and expenses for our Scout group
Wave is our accounting package, but inputs come from a variety of sources and can be time consuming to reconcile with each other and get good data into Wave.

There are ingesters that bring in data in bunches from some source or automatically. There is also an ingester that is triggered by a submission via the "Report a Program Expense" form that is implimented in Gravity forms.
There are a number of small plugins that impliment various related functionality.

## What's in here
### CLI Ingesters
 - Interac email. This connest to the admin@403to.ca inbox and reads apparent Interac e-transfer notifications and sorts them based on section and memo field. Output is added to the Transaction Inbox as transaction evidence.
 - ofx. This reads ofx files for the bank accounts and credit card. It adds the data as canonical transactions in the Transaction Inbox

### WordPress Plugins
 - gravityforms-wave-sync. This is what started the whole project. It should maybe be renamed to reflect the new architecture. It connects the "Report a Program Expense" Gravity form input to the Transaction Inbox. This really makes it an ingester and we'll maybe reorganize this at some point to reflect that. Probably not until the CLI ingesters get brought into the web interface though.
 - 403to-receipt-ocr. This adds helpers to improve the "Report a Program Expense" Gravity Form in a number of ways to ease use by Scouters and other volunteers. Most notably, it adds OCR capability to the receipt upload to pre-fill forms and present them for confirmation rather than requiring manual completion at all times. It also impliments detection of logged in status and pre-fills member data as well. It should be renamed to match the new SAT project.
 - transaction-inbox. This is a register where the canonical data from ofx files (and maybe Wave? Could we pull this in as well to do more matching?) is matched to more descriptive data from the Interac and Gravity forms ingesters. Once it is matched and reviewed, it can be pushed as "good" data to Wave.
 - Transaction-inbox-tools. This is a settings page and toolkit where we can manage deeper admin tasks related to the Transaction Inbox. This should maybe become a central control panel for the whole project and be renamed sat-tools?

## Development log
I'm not a proper developer, so I'm not really sure of best practices. I'll mostly keep dated notes here in an effort to keep things on track. It has grown quickly and I'm finding it challenging to hold the threads in my head. Especially as the AI (Copilot) sometimes rewinds and has other output glitches that interrupt the flow at times. However, if I'm honest my health challenges of the past 5 years, and maybe age, have made me less good at holding these things in my head than I once was. 

### October 10, 2026
Definitely not the first day of development, but I didn't really use coding tools for the first week and so I didn't keep good notes or track changes well. I've been familiarizing myself with VSCode and Git with some interactive instruction from Copilot. I switched to using Opus 5.5 today rather than Copilot "auto". I don't have an opinion yet, but I'm hoping the output situation may be more stable.
I am, somewhat unwisely, working on this code in the live 403TO.ca website. However, since this code isn't itself live yet I think the risk is relatively low. I am not entirely clear in this moment which pieces work and what things look like. I got things to various stages at various times, but really had to focus on other work. This is my assessment of where things stand. This is just based on a few minutes of viewing the tools and clicking a few buttons. It is far from exhaustive.
 - 403to-receipt-ocr is currently pointed at a copy of the live receipt submission page that is at https://403to.ca/rpe2. It appears to work in generating valid OCR and filling fields and also in filling in the user details from the active login. Detecting good values for payee will be an ongoing struggle that we will need to return to with concrete examples for improvement.
 - Transaction-inbox. This is added as a shortcode to the page at https://403to.ca/inbox-review/. There is currently no data in the inbox because I cleared it last week. However, the expense register on tab 2 is being populated successfully from Gravity Forms. I am still confused when looking at the inbox. I think because Wave is in an unknown state while I'm looking at this. I think we need a method for reconciling this. We can see balances through the API, so that can allow a basic sanity check, but temporarily loading a csv export from Wave is probably a good idea at least to get things started. After that we may be able to just use the inbox as the pathway into Wave and rely on it's certainty. 
 - Transaction-inbox-tools. It's pretty, but doesn't do much yet except empty the inbox so I can refill it as part of testing. We chould a csv ingester for temporarily importing transactions that exist in the Wave register. This will allow to match up everything and make recommendations.
 - Gravity-forms-wave-sync. Given that entries are appearing in the Transaction Inbox I assume this is at least basically working. However, there is an error in in the entry table "Cannot export: the category is not mapped to a Wave account ID." I don't think this is very relevant since we are not currently exporting to Wave. It may never be relevant since I think the Wave export will be done from the Trabsaction Inbox in future and the code that is generating the error should maybe simply be removed.
 ofx. I think this ingester is working fine. 
#### additional thoughts from Copilot when I showed it this log
##### Answers to questions already in your log
 - Wave → Inbox: the API can't read transactions. Transaction only exposes id, and Business has no transactions field. It can read current account balances, but not as of a past date. So a CSV import is the only way to see what's in the Wave register.
 - Gravity Forms export error: since 0.7.1 the category ID lookup is keyed by Wave account name, and it's only rebuilt when you click Discover accounts on the plugin settings page. Running it once should clear the error, if you ever need that export path.

##### Today's Wave state (Oct 10)

 - Operating and CG Funds are reconciled to Jul 31, 2026, so their cut-over can be Aug 1.
 - GC Visa has a reconciliation issue for the period ending Jul 27. The Visa OFX also has a gap from Jul 28 to Aug 25. Fix both before Visa goes into the inbox.
 - Wave's bank feed is off, so the inbox is the only route into Wave. Every export from the inbox must store the Wave transaction ID; that's the main protection against duplicates.

##### Things that will catch you again

 - Wave API: text arguments must be passed as GraphQL variables, not written inline. $WAVE_URL and $WAVE_API_URL are both in use, so check which one your command uses. Don't reuse the Timely2Wave business ID.
 - Tools 0.1.1: reset and purge no longer touch inbox_import_batches, because the review DB user can't read it. Batch history is kept on purpose.
 - Category values: one claim came through as "Venturers - Camp food", but the Wave account is "Sea Venturers - Camp food". Check that the form choices match the chart of accounts exactly.

##### One habit to add

 - Back up the database before any purge or reset, since you're working on the live site.