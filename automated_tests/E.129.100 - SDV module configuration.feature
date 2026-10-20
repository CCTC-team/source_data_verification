Feature: E.129.100 - The system shall support enabling and configuring the Source Data Verification external module, validate its configuration, and record configuration changes to the module's View Logs page.

  As a REDCap administrator
  I want to configure Source Data Verification for a project
  So that monitors, data entry users and data managers can carry out SDV

  Scenario: Enable external Module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    And I should NOT see "Source Data Verification - v1.0.0"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    Then I should see "Available Modules"
    And I click on the button labeled "Enable" in the row labeled "Source Data Verification"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Source Data Verification - v1.0.0"

  Scenario: E.129.100 - Enable and configure the module in a project
    Given I create a new project named "E.129.100" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/ProjectTypes/RepeatingEvents_DoubleArm_withDAGs.xml", and clicking the "Create Project" button
    And I click on the second link labeled "Manage"
    Then I should see "External Modules - Project Module Manager"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Source Data Verification - v1.0.0"
    Then I should see "Source Data Verification - v1.0.0"

    # Adding Test_User4 to Monitor role
    Given I click on the link labeled "User Rights"
    When I enter "Test_User4" into the field with the placeholder text of "Assign new user to role"
    And I click on the button labeled "Assign to role"
    And I select "Monitor" on the dropdown field labeled "Select Role" on the role selector dropdown
    When I click on the button labeled "Assign"
    Then I should see "Test User4" within the "Monitor" row of the column labeled "Username" of the User Rights table

    # VERIFY - a role cannot be both a monitor and a data entry role
    Given I click on the second link labeled "Manage"
    And I click on the button labeled "Configure"
    Then I should see "Configure Module"
    And I select "Monitor" on the dropdown field labeled "1. Monitor role(s)"
    And I select "Monitor" on the dropdown field labeled "1. Data entry role(s)"
    And I select "the changed field had been verified (recommended)" on the dropdown field labeled "A form marked 'SDV complete' is set back"
    When I click on the button labeled "Save"
    Then I should see "A user role cannot be both a monitor role and a data entry role."

    # VERIFY - an invalid ignore action tag is rejected
    Given I select "DataEntryPI" on the dropdown field labeled "1. Data entry role(s)"
    And I enter "SDV IGNORE" into the input field labeled "Action tag that excludes a field from SDV entirely"
    When I click on the button labeled "Save"
    Then I should see "The SDV ignore action tag must start with @"

    # Valid configuration
    Given I clear field and enter "@NOTMONITORED" into the input field labeled "Action tag that excludes a field from SDV entirely"
    And I enter "@ENDPOINT-\w+" into the input field labeled "Regex matching the action tags of fields that require SDV"
    And I select "DataManager" on the dropdown field labeled "1. Data manager role(s)"
    When I click on the button labeled "Save"
    Then I should see "Source Data Verification - v1.0.0"

    # VERIFY - configuration changes are written to the module's View Logs page
    When I click on the link labeled "View Logs"
    Then I should see "Configuration changed (project)"
    And I logout

    # VERIFY - SDV links are shown to users with an SDV role
    Given I login to REDCap with the user "Test_User4"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.129.100"
    Then I should see a link labeled "SDV Dashboard"
    And I should see a link labeled "SDV Plan"
    When I click on the link labeled "SDV Plan"
    Then I should see "The SDV plan sets which fields need Source Data Verification"
    And I should see "ptname_v2"
    And I should see "@ENDPOINT-PRIMARY"
    And I logout
