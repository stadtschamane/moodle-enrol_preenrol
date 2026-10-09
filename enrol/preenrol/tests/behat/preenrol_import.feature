@enrol @enrol_preenrol @javascript
Feature: Pre-enrolment import and auto-enrol on first signup
  In order to pre-enrol people who do not have an account yet
  As a course manager
  I need to import a list of e-mail addresses, so that the matching people are
  enrolled as soon as their accounts are created.

  # The plugin is enabled through the "Manage enrol plugins" admin UI on
  # purpose: enrol_plugins_enabled cannot be set reliably with the Behat
  # config step (CFG is read in the web session before the step runs), which
  # is the same approach core enrol features take (see enrol_meta.feature).
  # @javascript is used because the import page icon click and the
  # add-method singleselect are verified against the JS driver only.
  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | benexist | Ben       | Existing | ben@example.org      |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And I log in as "admin"
    And I navigate to "Plugins > Enrolments > Manage enrol plugins" in site administration
    And I click on "Enable" "link" in the "Pre-enrolment (email list)" "table_row"
    And I am on course index
    And I log out

  Scenario: Import enrols existing users and queues new signups
    # The account of ben@example.org already exists, so the import enrols
    # him right away (report category "Enrolled"). jane@example.org does not
    # exist yet, so the import queues her as a pending pre-enrolment.
    When I log in as "admin"
    And I add "Pre-enrolment (email list)" enrolment method in "Course 1" with:
      | Custom instance name | Import test |
    And I am on the "Course 1" "enrolment methods" page
    And I click on "Import pre-enrolments" "icon"
    And I set the field "E-mail addresses" to multiline:
    """
    ben@example.org
    jane@example.org
    """
    And I set the field "I confirm that the addresses listed above should be imported" to "1"
    And I press "Import pre-enrolments"
    Then I should see "Import report"
    And I should see "ben@example.org"
    And I should see "jane@example.org"
    # Creating the account fires the user_created event which the plugin
    # observes: the pending pre-enrolment for jane@example.org is consumed
    # and she becomes enrolled in Course 1.
    And the following "users" exist:
      | username | firstname | lastname | email            |
      | jane     | Jane      | Doe      | jane@example.org |
    And I am on the "Course 1" "enrolled users" page
    Then I should see "Ben Existing"
    And I should see "Jane Doe"