@mod @mod_googlemeet
Feature: Recorded classes in the Google Meet activity
  In order to follow classes I missed
  As a student
  I need to list the recordings and open a class hub, while teachers get management actions

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity   | name        | course | idnumber |
      | googlemeet | Live class  | C1     | gm1      |
    And the following "mod_googlemeet > recordings" exist:
      | googlemeet | name           |
      | Live class | Tema 1 intro   |

  Scenario: A student sees the recordings and opens the hub of one
    Given I am on the "Live class" "googlemeet activity" page logged in as student1
    Then I should see "Tema 1 intro"
    When I click on "Open class" "link"
    Then I should see "Tema 1 intro"
    And I should see "Summary"

  Scenario: A student does not see the Questions tab without published questions
    Given I am on the "Live class" "googlemeet activity" page logged in as student1
    When I click on "Open class" "link"
    Then I should not see "Questions" in the ".googlemeet-hub-tabs" "css_element"

  Scenario: A teacher sees the Actions menu in the list view
    Given I am on the "Live class" "googlemeet activity" page logged in as teacher1
    When I click on "List" "link"
    Then I should see "Actions" in the ".googlemeet-row-actions" "css_element"

  Scenario: Managing materials without a recording goes back to the activity
    Given I log in as "teacher1"
    When I visit the "material.php" script of googlemeet "Live class" with recording id "0"
    Then I should see "Choose a recording to manage its materials."
    And I should see "Tema 1 intro"

  Scenario: A stale recording link shows a friendly message
    Given I log in as "student1"
    When I visit the "view.php" script of googlemeet "Live class" with recording id "999999"
    Then I should see "This recording is no longer available or has been deleted."
    And I should see "Tema 1 intro"
