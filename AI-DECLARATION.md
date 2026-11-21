---
version: "1.0.0"
level: copilot
---

## Notes

Anthropic's Claude Opus 5.5 model, via Claude Code, wrote the initial implementation of this
module from the author's direction. That covers the module code, the prompts sent to the GenAI
service, the schema description, the static query validation, the Dynamic SQL editor and
Database Query Tool integrations, the AI disclaimer and this documentation. Parts of it (the
GenAI adapter, the panel and the disclaimer) were adapted from the author's REDCap Logic
Assistant module, which Claude also wrote under the author's direction.

Before writing code, Claude researched REDCap core and the database. In REDCap core, it looked at
how Dynamic SQL fields are edited and run, the Database Query Tool's placeholders and read-only
rules, and REDCap's bundled SQL parser. In the database's `information_schema`, it measured the
size of the schema, which led to the two-stage design: the AI picks tables first, then sees
definitions for those tables only. It also avoided a core method for reading user roles that
has write side effects.

The query validator was tested against REDCap's real SQL parser using a set of valid and invalid
example queries and a stand-in schema. It was not tested against a live database.

Aidan Wilson made all product decisions:

- the concept and scope: a separate module for administrators, covering Dynamic SQL fields and
  the Database Query Tool;
- the strict rule that only the database schema, never table data, is sent to the GenAI service;
- including the project's full structure (including roles) for Dynamic SQL;
- relying solely on REDCap's configured GenAI service;
- showing an "AI-generated" notice on every query;
- requiring a per-user disclaimer that is accepted once a year.
