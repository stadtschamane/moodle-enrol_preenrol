# Moodle Pre-Enrolment plugin (enrol_preenrol)

**What it does**: lets course managers pre-enrol users by importing a list of e-mail addresses into a course. The addresses are kept in a pending pre-enrolment table. As soon as a user account is created whose e-mail matches a pending entry (typically the user's first signup), that user is automatically enrolled into the course and the pending entry is removed.

## Import formats

1. **Pasted list (textarea)**: one e-mail per line; semicolons, commas and tabs are also accepted as separators. Whitespace is trimmed and addresses are normalised to lowercase.
2. **CSV file upload**: the file is parsed with the same rules as the pasted list (newline, semicolon, comma or tab separated), limit of 2000 rows per import.

## Privacy

Imported e-mail addresses are stored in the plugin's pending pre-enrolment table until enrolment happens (typically at account creation); before that they are not linked to a Moodle user ID. Deletion is handled through Moodle's Privacy API.

## Issue tracker

https://github.com/stadtschamane/moodle-enrol_preenrol/issues
