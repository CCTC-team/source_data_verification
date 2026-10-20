Feature: E.129.300 - The system shall provide an SDV dashboard of form SDV status, monitor queries and progress by site, restricted to the user's data access group, and an SDV plan page where monitors set which fields require SDV.

  As a monitor or site user
  I want to see SDV progress and open queries
  So that I can plan monitoring visits and resolve outstanding queries

  Scenario: Verify external module is enabled in Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    Then I should see "Source Data Verification - v1.0.0"

  Scenario: Set up the project with DAGs
    Given I create a new project named "E.129.300" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/ProjectTypes/RepeatingEvents_DoubleArm_withDAGs.xml", and clicking the "Create Project" button
    And I click on the second link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Source Data Verification - v1.0.0"

    # Test_User1 is a data entry user restricted to DAG1
    Given I click on the link labeled "User Rights"
    When I enter "Test_User1" into the field with the placeholder text of "Assign new user to role"
    And I click on the button labeled "Assign to role"
    And I select "DAG1" on the dropdown field labeled "Assign To DAG" on the role selector dropdown
    And I select "DataEntryPI" on the dropdown field labeled "Select Role" on the role selector dropdown
    When I click on the button labeled "Assign"
    Then I should see "Test User1" within the "DataEntryPI" row of the column labeled "Username" of the User Rights table
    When I enter "Test_User4" into the field with the placeholder text of "Assign new user to role"
    And I click on the button labeled "Assign to role"
    And I select "Monitor" on the dropdown field labeled "Select Role" on the role selector dropdown
    When I click on the button labeled "Assign"
    Then I should see "Test User4" within the "Monitor" row of the column labeled "Username" of the User Rights table

    Given I click on the link labeled "Data Import Tool"
    And I upload a "csv" format file located at "fixtures/import_files/RepeatingEvents_DoubleArm_withDAGs.csv", by clicking the button near "Select your CSV data file" to browse for the file, and clicking the button labeled "Upload File" to upload the file
    And I click on the button labeled "Import Data"
    Then I should see "Import Successful!"

    Given I click on the second link labeled "Manage"
    And I click on the button labeled "Configure"
    And I select "Monitor" on the dropdown field labeled "1. Monitor role(s)"
    And I select "DataEntryPI" on the dropdown field labeled "1. Data entry role(s)"
    And I enter "@ENDPOINT-\w+" into the input field labeled "Regex matching the action tags of fields that require SDV"
    And I enter "@NOTMONITORED" into the input field labeled "Action tag that excludes a field from SDV entirely"
    And I select "the changed field had been verified (recommended)" on the dropdown field labeled "A form marked 'SDV complete' is set back"
    When I click on the button labeled "Save"
    Then I should see "Source Data Verification - v1.0.0"
    And I logout

  Scenario: E.129.300 - SDV plan, dashboard and DAG restriction
    # VERIFY - monitors change the SDV plan for all records
    Given I login to REDCap with the user "Test_User4"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.300"
    And I click on the link labeled "SDV Plan"
    Then I should see "Text Validation"
    When I select the SDV plan requirement "excluded" for the field "email_v2"
    Then I should see "SDV requirement of email_v2 set to excluded."
    When I click on the link labeled "SDV Plan"
    Then I should see "Recent SDV plan changes"

    # VERIFY - the dashboard finds all saved forms that need SDV
    When I click on the link labeled "SDV Dashboard"
    Then I should see "SDV Dashboard"
    And I should see a link labeled "1-1"
    And I should see a link labeled "2-1"
    And I should see "SDV pending"

    # complete SDV on one form with only ptname_v2 now required
    When I click on the link labeled "Record Status Dashboard"
    And I click on the link labeled "1-1"
    When I click the bubble to select a record for the "Text Validation" longitudinal instrument on event "Event 2"
    Then I should see "Required fields verified: 0 / 1"
    When I click on the button labeled "Verify all required fields"
    And I click on the button labeled "Mark SDV complete"
    Then I should see the SDV form status "SDV complete"

    # VERIFY - status filter and summary
    When I click on the link labeled "SDV Dashboard"
    And I select "SDV complete" on the dropdown field labeled "SDV status"
    Then I should see 1 row in the SDV dashboard
    When I click on the link labeled "Summary"
    Then I should see "Site: DAG1"
    And I should see "Site: DAG2"
    And I logout

    # VERIFY - a DAG user only sees their own site's records
    Given I login to REDCap with the user "Test_User1"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.300"
    And I click on the link labeled "SDV Dashboard"
    Then I should see a link labeled "1-1"
    And I should NOT see the record "2-1" in the SDV dashboard
    And I logout
