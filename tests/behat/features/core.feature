Feature: Core content and authentication
  Quanta's core behaviours remain usable after changes to the CMS.
  Each scenario uses a fresh disposable site, real HTTP requests and real storage.

  Scenario: Render the homepage through the front controller
    When I visit "/home/"
    Then the response status is 200
    And the page contains "Welcome to the acceptance site"
    And the page contains "<h1>Acceptance home</h1>"
    And the page has no unresolved Qtags

  Scenario: Authenticate with the correct password
    When I log in as "administrator" with password "behat-test-password"
    And I visit "/home/"
    Then the current username is "administrator"

  Scenario: Reject an incorrect password
    When I log in as "administrator" with password "incorrect-password"
    And I visit "/home/"
    Then the current username is "anonymous"

  Scenario: Reject an unknown username
    When I log in as "missing-user" with password "behat-test-password"
    And I visit "/home/"
    Then the current username is "anonymous"

  Scenario: Logging out removes the authenticated session
    When I log in as "administrator" with password "behat-test-password"
    And I visit "/home/"
    Then the current username is "administrator"
    When I log out
    And I visit "/home/"
    Then the current username is "anonymous"

  Scenario: Create a node and reload it in a fresh PHP process
    When I create a page "acceptance-article" titled "A new article"
    Then loading "acceptance-article" returns title "A new article"
    When I visit "/acceptance-article/"
    Then the response status is 200
    And the page contains "<h1>A new article</h1>"
    And the page contains "Content saved by the acceptance test"
    And the page has no unresolved Qtags

  Scenario: A saved title survives another request
    When I create a page "editable-article" titled "Original title"
    And I change the title of "editable-article" to "Updated title"
    Then loading "editable-article" returns title "Updated title"
    When I visit "/editable-article/"
    Then the page contains "<h1>Updated title</h1>"
    And the page does not contain "Original title"

  Scenario: Deleted nodes are no longer loadable
    When I create a page "temporary-article" titled "Temporary article"
    And I delete the page "temporary-article"
    Then the page "temporary-article" does not exist in storage
