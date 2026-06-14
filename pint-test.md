# Pint Formatting Test Report
*Generated: dim. 14 juin 2026 13:36:39 WAT*


  ⨯⨯⨯...⨯................⨯⨯.⨯⨯....⨯⨯.⨯⨯......⨯.⨯.⨯.....⨯⨯⨯⨯⨯⨯...⨯⨯.....

  ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────── Laravel  
    FAIL   ................................................................................................................................................. 69 files, 23 style issues  
  ⨯ src/AbstractTask.php                                                                                          class_attributes_separation, braces_position, single_line_empty_body  
  ⨯ src/Contexts/TaskContext.php                                                                                                                           class_attributes_separation  
  ⨯ src/Contexts/TaskStorageContext.php                                                                                                      class_attributes_separation, concat_space  
  ⨯ src/Directives/ProcessTasksDirective.php                                                      new_with_parentheses, not_operator_with_successor_space, blank_line_before_statement  
  ⨯ src/Records/TaskPayloadRecord.php                                                                                       braces_position, no_unused_imports, single_line_empty_body  
  ⨯ src/Repositories/RecurringTaskRepository.php new_with_parentheses, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement, order…  
  ⨯ src/Repositories/TaskRepository.php  new_with_parentheses, concat_space, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement,…  
  ⨯ src/Services/TaskBatchService.php                                                                                    new_with_parentheses, braces_position, single_line_empty_body  
  ⨯ src/Services/TaskRegistryService.php                         concat_space, braces_position, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement  
  ⨯ src/Services/TaskRunnerService.php new_with_parentheses, braces_position, no_unused_imports, not_operator_with_successor_space, single_line_empty_body, blank_line_before_stateme…  
  ⨯ src/Strategies/TaskPathStrategy.php                                                                                     braces_position, no_unused_imports, single_line_empty_body  
  ⨯ src/TaskServiceProvider.php                                                                                        new_with_parentheses, concat_space, blank_line_before_statement  
  ⨯ src/ValueObjects/TaskDirectoryVO.php      concat_space, braces_position, no_unused_imports, not_operator_with_successor_space, single_line_empty_body, blank_line_before_statement  
  ⨯ src/ValueObjects/TaskIdVO.php                                                                                                      concat_space, not_operator_with_successor_space  
  ⨯ src/ValueObjects/TaskSignatureVO.php                                                                                               concat_space, not_operator_with_successor_space  
  ⨯ tests/Integration/AbstractTaskTest.php                                                                      concat_space, unary_operator_spaces, not_operator_with_successor_space  
  ⨯ tests/Integration/Repositories/RecurringTaskRepositoryTest.php class_attributes_separation, new_with_parentheses, concat_space, php_unit_method_casing, no_unused_imports, not_op…  
  ⨯ tests/Integration/Repositories/TaskRepositoryTest.php class_attributes_separation, new_with_parentheses, concat_space, php_unit_method_casing, no_unused_imports, not_operator_wi…  
  ⨯ tests/Integration/Services/TaskBatchServiceTest.php          class_attributes_separation, new_with_parentheses, concat_space, no_unused_imports, not_operator_with_successor_space  
  ⨯ tests/Integration/Services/TaskRunnerServiceTest.php class_attributes_separation, new_with_parentheses, concat_space, no_unused_imports, not_operator_with_successor_space, order…  
  ⨯ tests/Integration/Workflows/FailedTaskRetryTest.php            class_attributes_separation, new_with_parentheses, concat_space, not_operator_with_successor_space, ordered_imports  
  ⨯ tests/Integration/Workflows/RecurringTaskTest.php class_attributes_separation, new_with_parentheses, fully_qualified_strict_types, concat_space, not_operator_with_successor_spac…  
  ⨯ tests/Integration/Workflows/TaskLifecycleTest.php class_attributes_separation, new_with_parentheses, fully_qualified_strict_types, concat_space, not_operator_with_successor_spac…  

