# Pint Formatting Test Report
*Generated: dim. 14 juin 2026 16:51:41 WAT*


  ⨯⨯⨯...⨯................⨯⨯.⨯⨯⨯...⨯..⨯..⨯.⨯⨯..⨯⨯⨯......⨯.⨯.⨯.....⨯⨯⨯⨯⨯⨯⨯..⨯⨯⨯⨯⨯.....

  ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────── Laravel  
    FAIL   ................................................................................................................................................. 82 files, 32 style issues  
  ⨯ src/AbstractTask.php                                                                                          class_attributes_separation, braces_position, single_line_empty_body  
  ⨯ src/Contexts/TaskContext.php                                                                                                                           class_attributes_separation  
  ⨯ src/Contexts/TaskStorageContext.php                                                                                                      class_attributes_separation, concat_space  
  ⨯ src/Contracts/Services/TaskFinderServiceInterface.php                                                                                                                 phpdoc_align  
  ⨯ src/Contracts/Services/TaskRegistryServiceInterface.php                                                                                                          phpdoc_separation  
  ⨯ src/Contracts/Services/TaskServiceInterface.php                                                      class_definition, ordered_interfaces, braces_position, single_line_empty_body  
  ⨯ src/Directives/ProcessTasksDirective.php                                                      new_with_parentheses, not_operator_with_successor_space, blank_line_before_statement  
  ⨯ src/Directives/TaskUnregisterDirective.php                                                    new_with_parentheses, not_operator_with_successor_space, blank_line_before_statement  
  ⨯ src/Records/TaskPayloadRecord.php                                                                                       braces_position, no_unused_imports, single_line_empty_body  
  ⨯ src/Repositories/RecurringTaskRepository.php new_with_parentheses, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement, order…  
  ⨯ src/Repositories/TaskRepository.php  new_with_parentheses, concat_space, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement,…  
  ⨯ src/Services/TaskBatchService.php                                             new_with_parentheses, ordered_interfaces, braces_position, no_unused_imports, single_line_empty_body  
  ⨯ src/Services/TaskRegistryService.php concat_space, no_trailing_whitespace_in_comment, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_befo…  
  ⨯ src/Services/TaskRunnerService.php new_with_parentheses, braces_position, no_unused_imports, not_operator_with_successor_space, single_line_empty_body, blank_line_before_stateme…  
  ⨯ src/Services/TaskValidatorService.php                         unary_operator_spaces, braces_position, no_unused_imports, not_operator_with_successor_space, single_line_empty_body  
  ⨯ src/Strategies/TaskPathStrategy.php                                                                                     braces_position, no_unused_imports, single_line_empty_body  
  ⨯ src/TaskServiceProvider.php                                                                                        new_with_parentheses, concat_space, blank_line_before_statement  
  ⨯ src/ValueObjects/TaskDirectoryVO.php      concat_space, braces_position, no_unused_imports, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement  
  ⨯ src/ValueObjects/TaskIdVO.php                                                                                                      concat_space, not_operator_with_successor_space  
  ⨯ src/ValueObjects/TaskSignatureVO.php                                                                                               concat_space, not_operator_with_successor_space  
  ⨯ tests/Integration/AbstractTaskTest.php                                                                      concat_space, unary_operator_spaces, not_operator_with_successor_space  
  ⨯ tests/Integration/Directives/TaskUnregisterDirectiveTest.php                                             class_attributes_separation, blank_line_before_statement, ordered_imports  
  ⨯ tests/Integration/Repositories/RecurringTaskRepositoryTest.php class_attributes_separation, new_with_parentheses, concat_space, php_unit_method_casing, no_unused_imports, not_op…  
  ⨯ tests/Integration/Repositories/TaskRepositoryTest.php class_attributes_separation, new_with_parentheses, concat_space, php_unit_method_casing, no_unused_imports, not_operator_wi…  
  ⨯ tests/Integration/Services/TaskBatchServiceTest.php          class_attributes_separation, new_with_parentheses, concat_space, no_unused_imports, not_operator_with_successor_space  
  ⨯ tests/Integration/Services/TaskFinderServiceTest.php                            class_attributes_separation, fully_qualified_strict_types, php_unit_method_casing, ordered_imports  
  ⨯ tests/Integration/Services/TaskRegistryServiceTest.php                                                                                          new_with_parentheses, single_quote  
  ⨯ tests/Integration/Services/TaskRunnerServiceTest.php class_attributes_separation, new_with_parentheses, concat_space, no_unused_imports, not_operator_with_successor_space, order…  
  ⨯ tests/Integration/Services/TaskServiceTest.php                                                                                                              php_unit_method_casing  
  ⨯ tests/Integration/Workflows/FailedTaskRetryTest.php            class_attributes_separation, new_with_parentheses, concat_space, not_operator_with_successor_space, ordered_imports  
  ⨯ tests/Integration/Workflows/RecurringTaskTest.php class_attributes_separation, new_with_parentheses, fully_qualified_strict_types, concat_space, not_operator_with_successor_spac…  
  ⨯ tests/Integration/Workflows/TaskLifecycleTest.php class_attributes_separation, new_with_parentheses, fully_qualified_strict_types, concat_space, not_operator_with_successor_spac…  

