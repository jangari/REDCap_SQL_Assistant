# REDCap SQL Assistant

This REDCap External Module helps administrators write SQL. It adds a **Generate query with AI** assistant in two places:

- **Dynamic SQL fields** in the Online Designer.
- The **Database Query Tool** in the Control Center.

Administrators describe the query they need in plain language. For example:

- *"List this project's records as the value, with first and last name as the label, sorted by last name"*
- *"Projects in production with no user rights changes in the last 12 months"*

The module then suggests a query, with a plain-English explanation and any assumptions it made.

<!-- TODO: add screencast.gif -->

**Only the database schema is sent to the AI service, never data from the tables.** Generated queries are checked before they are shown. They are never run or applied automatically.

The assistant is available to **super users only**.

## Requirements

- REDCap 17.0.1 or later (External Module Framework version 17).
- A GenAI service configured in REDCap (**Control Center → GenAI Settings**): Azure OpenAI, an OpenAI-compatible endpoint, or Google Gemini.

The module uses whichever GenAI service is configured at the system level, or the project's override if one is set:

- The **Database Query Tool** is not part of a project, so it uses the system-level configuration.
- **Dynamic SQL fields** use the project's configuration.

Wherever no GenAI service is enabled, the assistant does not appear.

## Installation

### From Module Repository

Install the module from the REDCap module repository and enable it in the Control Center.

### From GitHub

Clone the repository and rename the directory to include a version number, e.g., `sql_assistant_v1.0.0`, and copy to your modules directory, then enable in the Control Center.

### Making it available in projects

The Database Query Tool assistant works as soon as the module is enabled in the Control Center.

Like any module, it only runs in projects where it is enabled. To make the Dynamic SQL assistant available in every project, choose one of these:

- **Recommended:** check **Enable module on all projects by default**.
- Enable it on individual projects as needed.

Either way, also check **Hide this module from non-admins in the list of enabled modules on each project**. The assistant is only ever shown to super users.

## Usage

### Dynamic SQL fields

In the Online Designer, add or edit a field of type **Dynamic Query (SQL)**. Click into the query box to open the editor. Then:

1. Click **Generate query with AI**. The first time, a disclaimer is shown, which must be accepted (see [Disclaimer](#disclaimer)).
2. Describe the dropdown you need, then click **Generate** (or press Ctrl/Cmd+Enter). If the editor already contains a query, the assistant takes it into account, so you can also ask it to change the existing query.
3. Review the query, explanation and any assumptions. Then choose **Replace query**, **Insert at cursor** or **Copy**.

The assistant follows REDCap's rules for Dynamic SQL:

- The query starts with `SELECT`.
- It returns a value column, then an optional label column.
- It uses `[project-id]` rather than the literal project ID, so the field still works when the project is copied.
- It writes `redcap_data` for record data, which REDCap automatically swaps for the project's own data table.
- It uses Smart Variables such as `[record-name]` and `[user-name]` without adding extra quotes.

### Database Query Tool

On **Control Center → Database Query Tool**, the assistant appears above the query box.

Queries can use the page's placeholders (`[project-id]`, `[record-name]`, `[event-id]`, `[instrument-name]`, `[current-instance]`, `[user-name]`, `[survey-id]` and `[user-id]`). This lets one query be reused with the context boxes rather than hard-coding values.

### Disclaimer

The first time the **Generate query with AI** button is clicked, in either location, a disclaimer is shown, which must be accepted. It covers:

- AI-generated queries may be wrong.
- Running a query, or a Dynamic SQL field, is the user's responsibility.
- What is sent to the AI service.
- Not including sensitive information in requests.

Acceptance is remembered for each REDCap user, in a browser cookie that includes their username, for one year.

### Checks before a query is shown

Before a generated query is shown, it is checked. **It is never run**, not even with `EXPLAIN`. The checks are:

- It is a single statement.
- It is read-only by the same rule the Database Query Tool uses (`SELECT`, `SHOW` or `EXPLAIN`). It must not use `INTO OUTFILE`, `LOAD_FILE`, `SLEEP`, `FOR UPDATE` and so on.
- For Dynamic SQL, it starts with `SELECT`.
- Every table it uses exists, and every column written as `table.column` or `alias.column` exists in that table. This uses REDCap's bundled SQL parser.

If a check fails, the problems are sent back to the assistant for up to two corrected attempts. Anything still wrong is listed under **Check before using**.

Every generated query is shown with a notice that it was generated by AI and must be reviewed, verified and tested before use.

### What is sent to the AI service

The full schema is too large to send with every request, so each request is made in two steps:

1. **Choosing tables.** The AI service receives only the list of REDCap table names and table comments, plus your request. It picks the tables it needs.
2. **Writing the query.** The AI service receives the full definitions of those tables, plus a small set of tables that most queries need. For each table it gets column names, types, keys, nullability, foreign keys and column comments.

All of this comes from MySQL's `information_schema`. **No rows are read from any REDCap table for the schema**, and statistics such as row counts are deliberately not included.

For **Dynamic SQL** only, the request also includes the project's structure:

- the project ID and its record data table
- arms, and events with their IDs
- instruments, with their survey IDs and repeating setup
- user roles: IDs and names only
- Data Access Groups: IDs and names
- fields, with their types, labels and choice codes

**No record data, usernames or user rights are sent.**

## Configuration

### System settings

| Setting | Default Value | Description |
| --- | --- | --- |
| Site-specific guidance | | Optional notes added to every request, e.g. conventions for your Dynamic SQL fields, or notes about tables your site has added. |
| Daily AI token limit per project (Dynamic SQL) | No limit | The most AI tokens each project's Dynamic SQL assistant can use per day. Blank or 0 means no limit. |
| Daily AI token limit for the Database Query Tool | No limit | The most AI tokens the Database Query Tool assistant can use per day, shared by all administrators. Blank or 0 means no limit. |

### Project settings

| Setting | Default Value | Description |
| --- | --- | --- |
| Daily AI token limit for this project (Dynamic SQL) | System limit | Overrides the system limit for this project. Blank uses the system limit, and 0 means no limit for this project. Anything other than a whole number is ignored, and the system limit applies. Only super users can see or change this setting, so project designers can't raise their own limit. |

### Usage limits

Limits are counted in AI tokens, and reset at midnight server time:

- **Dynamic SQL** requests count toward their project's limit.
- **Database Query Tool** requests count toward a separate limit, shared by all administrators.

Each request's tokens are taken from the usage that the AI service reports. If the service doesn't report usage, they are estimated at about 4 characters per token. Requests that fail after using tokens also count.

When a limit has already been reached, the assistant refuses new requests until midnight, with a message saying so.

If a limit is reached part-way through a request, the assistant stops making corrections and shows the query it has, with a note. Any problems with that query are still listed under **Check before using**. This means usage can go over a limit by up to one request's tokens.

Requests made before token counting was added have no token count, so they count as 0 toward the day's total.

## Logging

Each request is recorded in the module's log with:

- whether it was for Dynamic SQL or the Database Query Tool
- the number of attempts
- whether the final query passed the checks
- whether the assistant asked a clarifying question instead of returning a query
- whether a usage limit stopped the assistant from making corrections
- the number of AI tokens used
- how many tables were described to the AI service
- which type of GenAI service was used

Requests that fail after using AI tokens are also logged, with the number of tokens used, so that they count toward the usage limits.

The text of the request and the generated SQL are **not** logged.

Each call to the GenAI service is also recorded in REDCap's own GenAI usage log, as REDCap's built-in AI features are, with the type `SqlAssistant`. A request makes two to four calls. REDCap stores only the number of characters and words sent and received, not the text.

## Limitations

- The module relies on REDCap's internal AI classes to use the configured GenAI service. These are not part of the documented External Module Framework, so a future REDCap release could break the module until it is updated.
- On the Dynamic SQL editor, the assistant is added by wrapping REDCap's own logic editor, which core also uses for SQL fields. If a future REDCap version changes this editor, the assistant may not appear. The editor itself will continue to work normally.
- The checks catch invalid tables and columns, but not every mistake. A query can pass and still return the wrong rows. Columns written without a table or alias are only flagged as warnings, because the SQL parser can't always tell them apart from keywords.
- On the Database Query Tool, the assistant can't know which project's data table (`redcap_data`, `redcap_data2`, …) a query should read. It says so in its explanation when it matters.

## Todo

- Propose a framework-level AI method to the External Module Framework, so modules can use REDCap's GenAI configuration without relying on internal classes.
- Internationalisation via the External Module Framework's i18n features.

## Citation

If you use this external module for a project that generates a research output, please cite this software in addition to [citing REDCap](https://projectredcap.org/resources/citations/) itself. You can do so using the APA referencing style as below:

> Wilson, A. (2026). REDCap SQL Assistant [Computer software]. https://github.com/jangari/redcap_sql_assistant

Or by adding this reference to your BibTeX database:

```bibtex
@software{Wilson_REDCap_SQL_Assistant_2026,
  title        = {{REDCap SQL Assistant}},
  author       = {Wilson, Aidan},
  year         = 2026,
  month        = 11,
  url          = {https://github.com/jangari/redcap_sql_assistant}
}
```

These instructions are also available in [GitHub](https://github.com/jangari/redcap_sql_assistant/) under 'Cite This Repository'.

## AI Involvement Declaration

The concept and requirements for this module were developed by Aidan Wilson. The initial implementation was written by Anthropic's Claude (Opus 5.5) via Claude Code, working from the author's direction. Design decisions were reviewed and tested by the author: sending schema only and never table data, using REDCap's existing GenAI configuration, checking queries without running them, and restricting the module to super users.

## Changelog

| Version | Description |
| --- | --- |
| 1.0.0 | Initial release. |
