# Scout Accounting Toolkit

Scout Accounting Toolkit is a collection of open-source tools designed to help Scout groups manage payments, banking transactions, expense claims, and accounting workflows using low-cost and volunteer-friendly systems. 

I am a Scouter and sysadmin in Toronto, Ontario, Canada. The project was originally developed for the 403rd Toronto Sea Scout Group but is being generalized for use by other Scout groups and volunteer organizations.

This is volunteer-built software for volunteer organizations, and improvements should remain available to the Scouting community.

## License

Scout Accounting Toolkit is released under the GNU General Public License v3.0 (GPL-3.0).

This project was created to support Scout groups, volunteers, and other community organizations. The GPL ensures that improvements and derivative works remain available to the community under the same freedoms that made this project possible. You are free to use, study, modify, and redistribute this software under the terms of the GPL.

## Why this project exists

Many Scout groups collect information and money through multiple disconnected systems:

- Bank accounts
- Credit cards
- Interac e-Transfers
- Online forms
- Expense claims
- Accounting software

Volunteer treasurers often spend significant time manually reconciling these systems.

Scout Accounting Toolkit provides tools to:

- Import transactions automatically
- Collect supporting evidence
- Review and categorize transactions
- Track allocations and claim matching
- Prepare data for accounting systems

## Open Source Philosophy

Many volunteer organizations lack the resources to purchase expensive accounting and workflow software.

This project aims to provide a practical, transparent, and community-driven alternative. Contributions, fixes, and improvements should remain available to the wider community, which is why Scout Accounting Toolkit uses the GPL rather than a more permissive license.

## Design Goals
 - Volunteer-friendly (though it may require a bit of help from a more technically minded Scout or Scouter to get set up)
 - Low cost (I'm using free tools as much as possible and am not charging you for this code)
 - Open Source (I'm a staunch advocate of Free/Libre principles and supporter of the Free Software Foundation)
 - Auditability (I try to preserve everything)
 - Security-consciousness
 - Minimal duplication of information
 - Simple deployment (unfortunately it requires the setup of at least two API keys, but I'm doing my best to keep it simple)

## Current Components

### Interac Email Ingestion

Imports incoming Interac payment notifications from a mailbox and stores them in a transaction inbox.

Capabilities:

- IMAP mailbox processing
- Duplicate detection
- Payment extraction
- Import batch tracking
- Transaction observation storage

### OFX Bank Ingestion

Imports OFX exports from supported banks and credit card providers.

Capabilities:

- OFX parsing
- Batch import management
- Transaction normalization
- Duplicate detection
- Unified inbox integration

### Transaction Inbox

WordPress-based transaction review system.

Capabilities:

- Transaction review
- Expense claim matching
- Transaction categorization
- Split allocations
- Batch acceptance workflows
- Receipt evidence display

### Transaction Inbox Tools

Administrative and operational utilities for transaction review and maintenance.

### Gravity Forms Wave Sync

Integrates Gravity Forms submissions with accounting workflows.

Capabilities:

- Wave account discovery
- Mapping of form categories to accounting accounts
- Expense evidence capture
- Manual Wave export workflows
- Transaction Inbox integration

## Architecture

```text
Interac Emails
        |
        v
+----------------+
| Interac Import |
+----------------+
        |
        v

 OFX Files
        |
        v
+------------+
| OFX Import |
+------------+
        |
        v

+-------------------+
| Unified Inbox     |
| Observations      |
+-------------------+
        |
        v

+-------------------+
| Transaction Inbox |
+-------------------+
        |
        +---- Expense Claims
        |
        +---- Allocations
        |
        +---- Matching
        |
        +---- Review
        |
        v

+-------------------+
| Wave Accounting   |
+-------------------+