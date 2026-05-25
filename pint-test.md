# Pint Formatting Test Report
*Generated: lun. 25 mai 2026 07:36:41 WAT*


  ⨯.......⨯.⨯⨯.⨯⨯⨯⨯⨯⨯......⨯⨯⨯⨯⨯⨯⨯⨯.⨯⨯⨯⨯⨯.⨯⨯.⨯⨯....⨯⨯⨯!!!

  ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────── Laravel  
    FAIL   ....................................................................................................................................... 55 files, 3 errors, 30 style issues  
  ! app/Tasks/CustomTask.php                                                                                        Parse error: syntax error, unexpected token "namespace" on line 5.  
  ! app/Tasks/SendWelcomeEmailTask.php                                                                              Parse error: syntax error, unexpected token "namespace" on line 1.  
  ! app/Tasks/TestTask.php                                                                                          Parse error: syntax error, unexpected token "namespace" on line 5.  
  ⨯ src/AbstractTask.php                                       class_attributes_separation, new_with_parentheses, braces_position, single_line_empty_body, blank_line_before_statement  
  ⨯ src/Collections/TaskCollection.php                                                                              new_with_parentheses, blank_line_before_statement, ordered_imports  
  ⨯ src/Directives/MakeTaskDirective.php                       class_attributes_separation, single_quote, concat_space, not_operator_with_successor_space, blank_line_before_statement  
  ⨯ src/Directives/RunTaskDirective.php                                                           new_with_parentheses, not_operator_with_successor_space, blank_line_before_statement  
  ⨯ src/Services/ProcessManager.php                    class_attributes_separation, new_with_parentheses, concat_space, not_operator_with_successor_space, blank_line_before_statement  
  ⨯ src/Services/TaskRegistry.php                                       new_with_parentheses, concat_space, braces_position, not_operator_with_successor_space, single_line_empty_body  
  ⨯ src/Services/TaskRunner.php            new_with_parentheses, concat_space, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement  
  ⨯ src/Services/TaskStorage.php                                                    class_attributes_separation, new_with_parentheses, concat_space, not_operator_with_successor_space  
  ⨯ src/Services/TaskValidator.php                                                                                             new_with_parentheses, not_operator_with_successor_space  
  ⨯ src/TaskServiceProvider.php                                                                                        new_with_parentheses, concat_space, blank_line_before_statement  
  ⨯ tests/Fixtures/Tasks/FailingTask.php                                                                                                                   class_attributes_separation  
  ⨯ tests/Fixtures/Tasks/TestTask.php                                                                                                                      class_attributes_separation  
  ⨯ tests/Integration/Directives/MakeTaskDirectiveIntegrationTest.php                                     class_attributes_separation, concat_space, not_operator_with_successor_space  
  ⨯ tests/Integration/Directives/RunTaskDirectiveIntegrationTest.php                                                                                              new_with_parentheses  
  ⨯ tests/Integration/Services/ProcessManagerLockTest.php                                 class_attributes_separation, new_with_parentheses, concat_space, blank_line_before_statement  
  ⨯ tests/Integration/Services/ProcessManagerSequentialTest.php                                                                      class_attributes_separation, new_with_parentheses  
  ⨯ tests/Integration/Services/ProcessManagerTest.php                          class_attributes_separation, new_with_parentheses, no_trailing_whitespace_in_comment, phpdoc_separation  
  ⨯ tests/Integration/Services/TaskRunnerGracePeriodTest.php                                                                         class_attributes_separation, new_with_parentheses  
  ⨯ tests/Integration/Services/TaskRunnerTest.php                                                                   class_attributes_separation, new_with_parentheses, ordered_imports  
  ⨯ tests/Integration/Services/TaskValidatorEnforceExactScheduleTest.php                                                                    new_with_parentheses, no_extra_blank_lines  
  ⨯ tests/Integration/Services/TaskValidatorGracePeriodTest.php                                                                                                   new_with_parentheses  
  ⨯ tests/Integration/Services/TaskValidatorTest.php                                                                                 class_attributes_separation, new_with_parentheses  
  ⨯ tests/Integration/Workflows/FailedTaskRetryTest.php                                                                              class_attributes_separation, new_with_parentheses  
  ⨯ tests/Integration/Workflows/RecurringTaskTest.php                                                                                class_attributes_separation, new_with_parentheses  
  ⨯ tests/Integration/Workflows/TaskLifecycleTest.php                                                                                class_attributes_separation, new_with_parentheses  
  ⨯ tests/IntegrationTestCase.php                                                                                                                                         concat_space  
  ⨯ tests/Unit/AbstractTaskTest.php                                                 class_attributes_separation, new_with_parentheses, concat_space, not_operator_with_successor_space  
  ⨯ tests/Unit/Collections/TaskCollectionTest.php                                                                                                                 new_with_parentheses  
  ⨯ tests/Unit/Services/TaskRegistryTest.php                                                                                         class_attributes_separation, new_with_parentheses  
  ⨯ tests/Unit/Services/TaskStorageTest.php                                         class_attributes_separation, new_with_parentheses, concat_space, not_operator_with_successor_space  

