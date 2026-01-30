# Message Broker Edge Case & Failure Mode Test Scenarios

**Date**: 2026-01-30
**Status**: Draft
**Purpose**: Comprehensive test scenario inventory for expanding functional test coverage beyond happy path

## Context

Current test coverage focuses on happy-path scenarios:
- ✅ Outbox: Event dispatch → storage → AMQP publishing
- ✅ Inbox: AMQP consumption → deserialization → deduplication → handler
- ✅ Serialization: Value objects, semantic names, stamps
- ✅ Basic deduplication: First message vs duplicate

**Gap**: Missing coverage for edge cases, failure modes, concurrent scenarios, and validation resilience.

**Goal**: Create comprehensive test scenario inventory organized by risk category, prioritized for implementation.

---

## Test Scenario Categories

### 1. Data Integrity (Transactional Guarantees)

**Focus**: Preventing data loss, duplicate processing, inconsistent state

#### 1.1 Handler Exception & Rollback

**Priority**: 🔴 CRITICAL

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Handler throws exception during first processing | Publish message → handler throws RuntimeException | Deduplication entry should NOT be committed (transaction rollback) | ✅ No entry in deduplication table<br/>✅ Message stays in queue (NACK)<br/>✅ Message available for retry |
| Handler throws exception on retry | Message retried after handler failure | Each retry attempt should check deduplication but not commit entry until success | ✅ No dedup entry until handler succeeds<br/>✅ Message processed exactly once after N retries |
| Database constraint violation in handler | Handler attempts duplicate insert | Transaction rolls back, message can be retried | ✅ No dedup entry<br/>✅ No partial handler changes committed |
| Deduplication INSERT succeeds but handler fails | Race: dedup entry commits before handler exception | Deduplication and handler should be in same transaction (atomic) | ✅ Dedup entry rolled back with handler<br/>✅ Message can be reprocessed |

**Implementation Notes**:
- Create test fixtures: `ThrowingHandler`, `DatabaseConstraintHandler`
- Use Doctrine transaction debugging to verify rollback
- Test with middleware priority verification (dedup runs AFTER doctrine_transaction)

---

#### 1.2 Deduplication Edge Cases

**Priority**: 🔴 CRITICAL

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Message without MessageIdStamp header | AMQP message missing X-Message-Stamp-MessageIdStamp | Should reject message (cannot deduplicate without ID) | ✅ SerializationException thrown<br/>✅ Message moved to failed transport<br/>✅ No handler invocation |
| Message with invalid UUID in MessageIdStamp | MessageIdStamp contains non-UUID value | Should reject message | ✅ Validation error<br/>✅ Message to failed transport |
| Duplicate message arrives during first message processing | Concurrent workers consume same message ID simultaneously | Only one should process, other should detect duplicate | ✅ Handler invoked once<br/>✅ One dedup entry<br/>✅ Both workers ACK message |
| Message ID reuse after cleanup | Same messageId appears after dedup record deleted (old cleanup) | Should process as new message | ✅ Handler invoked<br/>✅ New dedup entry created |
| Message with messageId in payload (legacy format) | Body contains `messageId` property in addition to stamp | Should ignore payload messageId, use stamp only | ✅ Stamp messageId used for deduplication<br/>✅ Payload messageId ignored |

**Implementation Notes**:
- Manually construct AMQP messages without stamps
- Test concurrent scenario with 2 workers + parallel publish
- Simulate deduplication table cleanup between tests

---

#### 1.3 Transactional Publishing (Outbox)

**Priority**: 🟡 HIGH

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Business transaction rolls back before outbox commit | Dispatch event → business logic throws exception | Event should NOT be in outbox table (transaction atomicity) | ✅ No entry in messenger_outbox<br/>✅ Event not published to AMQP |
| Multiple events in single transaction | Single transaction dispatches 3 events | All or nothing: all events in outbox OR none | ✅ 3 events in outbox OR 0 events<br/>✅ No partial commits |
| Outbox worker crashes mid-processing | Worker ACKs from outbox transport but crashes before AMQP publish | Message should remain in outbox (Symfony marks as delivered but doesn't delete) | ✅ Message still in messenger_outbox (delivered_at set)<br/>✅ Can be reprocessed (manual recovery) |
| Bridge publishes to AMQP but fails to ACK outbox | AMQP publish succeeds but worker crashes before ACK | AMQP message exists, outbox message reprocessed | ✅ Duplicate in AMQP (acceptable - inbox deduplicates)<br/>✅ Outbox message reprocessed |

**Implementation Notes**:
- Wrap event dispatch in Doctrine transaction with rollback
- Use Worker with message limit to simulate controlled shutdown
- May need manual database inspection for crash scenarios

---

### 2. Error Handling & Recovery

**Focus**: Graceful degradation, retry mechanisms, failed message inspection

#### 2.1 Serialization Errors

**Priority**: 🟡 HIGH

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Invalid JSON in message body | Manually publish malformed JSON to AMQP | Should reject and move to failed transport | ✅ SerializationException<br/>✅ Message in failed transport<br/>✅ No handler invocation |
| Missing required property in body | JSON missing `orderId` field for OrderPlaced | Should reject with clear error | ✅ Deserialization error<br/>✅ Failed transport<br/>✅ Error message identifies missing property |
| Extra unknown properties in body | JSON has unexpected fields | Should ignore extra fields (forward compatibility) | ✅ Message deserialized successfully<br/>✅ Handler receives valid object<br/>✅ Extra fields ignored |
| Type mismatch in property | `totalAmount` sent as string instead of float | Should attempt type coercion or reject | ✅ Deserialization handles coercion<br/>OR ✅ Clear type error |
| Missing `type` header | AMQP message without semantic name header | Cannot route to handler, reject | ✅ SerializationException<br/>✅ Failed transport |
| Unmapped `type` header value | `type: unknown.event.name` not in message_types config | Cannot translate to FQN, reject | ✅ Clear error message<br/>✅ Failed transport |
| UUID string format variations | UUID with/without hyphens, uppercase/lowercase | Should normalize to consistent format | ✅ Id objects created successfully |
| Timestamp format variations | ISO 8601 with/without microseconds, different timezones | Should parse all valid ISO formats | ✅ CarbonImmutable objects created<br/>✅ Timezone preserved |

**Implementation Notes**:
- Create invalid message fixtures
- Test InboxSerializer directly with malformed input
- Verify failed transport inspection

---

#### 2.2 Connection Failures & Timeouts

**Priority**: 🟡 HIGH

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| AMQP connection drops during consumption | Kill RabbitMQ container mid-test | Consumer should reconnect and resume | ✅ Connection re-established<br/>✅ Messages continue processing |
| AMQP unavailable during publishing | Stop RabbitMQ before outbox worker runs | Bridge should retry, message remains in outbox | ✅ AMQP connection error logged<br/>✅ Message remains in outbox<br/>✅ Worker retries later |
| Database connection timeout during deduplication | Simulate slow query or connection timeout | Should fail gracefully, message can retry | ✅ Database error logged<br/>✅ Message NACK'd<br/>✅ No partial state |
| RabbitMQ queue full (memory limit) | Publish messages until queue full | Publisher should get resource error | ✅ AMQP exception<br/>✅ Message remains in outbox |
| Network partition between app and RabbitMQ | Use Docker network to simulate partition | System should detect failure and recover | ✅ Connection error detected<br/>✅ Reconnection after partition heals |

**Implementation Notes**:
- Use Docker compose to stop/start containers
- May need Docker network manipulation
- Test with Messenger retry_strategy configuration

---

#### 2.3 Failed Message Recovery & Inspection

**Priority**: 🟢 MEDIUM

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Inspect failed message from inbox | Message fails deserialization → failed transport | Should be able to read failed message details | ✅ `messenger:failed:show` displays message<br/>✅ Error details visible |
| Retry failed message after fix | Fix configuration → retry failed message | Message should process successfully | ✅ Handler invoked<br/>✅ Dedup entry created<br/>✅ Message removed from failed |
| Failed message retry with different serializer | Original serializer changed between failure and retry | InboxSerializer should handle both formats | ✅ Message retried successfully |
| Failed outbox message inspection | Outbox→AMQP bridge fails | Should identify cause and allow manual intervention | ✅ Failed message viewable<br/>✅ Error trace available |

**Implementation Notes**:
- Use `messenger:failed:show` and `messenger:failed:retry` commands
- Test with configuration changes between failure/retry

---

### 3. Concurrency & Race Conditions

**Focus**: Parallel processing safety, SKIP LOCKED, deduplication races

#### 3.1 Concurrent Message Processing

**Priority**: 🔴 CRITICAL

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Two workers consume from same queue simultaneously | Start 2 workers → publish 10 messages | Messages distributed evenly, no duplicate processing | ✅ All 10 messages handled exactly once<br/>✅ 10 dedup entries<br/>✅ No duplicate handler invocations |
| Race condition in deduplication INSERT | 2 workers consume identical messageId at exact same time | Only one worker processes, other detects duplicate via exception | ✅ Handler invoked once<br/>✅ One worker gets UniqueConstraintViolation<br/>✅ Both workers ACK |
| Message redelivery during processing | Worker 1 processing → message redelivered to Worker 2 | Worker 2 should detect duplicate in dedup table | ✅ Handler invoked once<br/>✅ Worker 2 finds existing dedup entry |
| Outbox worker parallelism | 3 workers consume from outbox transport | Messages published to AMQP without duplication | ✅ Each outbox message published once<br/>✅ Distributed across workers |
| Database lock contention on deduplication table | High message volume → many dedup INSERTs | Should handle contention gracefully (wait/retry) | ✅ No deadlocks<br/>✅ All messages processed |

**Implementation Notes**:
- Use Process or Symfony Process component to spawn parallel workers
- Publish messages in rapid succession
- Monitor for database lock waits in MySQL logs

---

#### 3.2 Message Redelivery Timing

**Priority**: 🟢 MEDIUM

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Message redelivered before handler completes | Slow handler (sleep 5s) → RabbitMQ redelivers after 3s | Second delivery should wait for first to commit dedup | ✅ Handler invoked once<br/>✅ Dedup entry exists when second delivery checks |
| Worker crash during handler execution | Worker killed mid-handler (no transaction commit) | Message redelivered, processed again (no dedup entry) | ✅ Handler re-executed<br/>✅ Dedup entry created on success |
| Acknowledgement timeout | Handler completes but ACK delayed | Message may be redelivered, dedup prevents re-processing | ✅ Duplicate detected<br/>✅ Handler not re-invoked |

**Implementation Notes**:
- Create slow handler fixture (sleep)
- Use RabbitMQ prefetch_count and delivery timeout settings
- Simulate worker crash with kill signal

---

### 4. Validation & Security

**Focus**: Input validation, resource limits, injection prevention

#### 4.1 Input Validation & Sanitization

**Priority**: 🟡 HIGH

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| SQL injection attempt in message payload | Payload contains SQL keywords in string fields | Should treat as literal strings, not execute | ✅ No SQL execution<br/>✅ Parameterized queries used<br/>✅ Value stored literally |
| XSS payload in message | Payload contains `<script>` tags | Should store literally, not interpret | ✅ No script execution<br/>✅ Escaped on storage |
| Command injection in message | Payload contains shell command characters | Should treat as literal data | ✅ No command execution |
| Path traversal attempt | Payload contains `../../../etc/passwd` | Should validate as data, not file path | ✅ No file access |
| Null byte injection | Payload contains null bytes (`\0`) | Should handle or reject gracefully | ✅ Clear error OR sanitized |
| Unicode edge cases | Payload with emojis, RTL marks, zero-width chars | Should handle UTF-8 correctly | ✅ Stored and retrieved correctly |

**Implementation Notes**:
- Create malicious payload fixtures
- Verify parameterized queries (Doctrine DBAL protects by default)
- Test string storage and retrieval

---

#### 4.2 Resource Limits & DoS Prevention

**Priority**: 🟢 MEDIUM

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Oversized message payload (10MB+) | Publish very large JSON body | Should reject or handle based on limits | ✅ Size limit enforced<br/>✅ Clear error message<br/>OR ✅ Streaming/chunking |
| Deeply nested JSON (1000+ levels) | Payload with excessive nesting | Should reject or limit depth | ✅ Parsing error<br/>✅ Stack overflow prevented |
| Excessive array size (100k+ elements) | Payload with huge array | Should reject or paginate | ✅ Memory limit not exceeded<br/>✅ Clear error |
| Message flood (100k messages/sec) | Publish massive message volume | System should handle backpressure | ✅ RabbitMQ queue depth monitored<br/>✅ Workers scale appropriately<br/>✅ No OOM |
| Infinite loop in handler | Handler enters infinite loop | Worker time limit should kill it | ✅ Worker timeout enforced<br/>✅ Message moved to failed |

**Implementation Notes**:
- Configure Messenger `time_limit` and `memory_limit`
- Test with RabbitMQ queue depth monitoring
- May need performance testing tools (JMeter, Locust)

---

#### 4.3 Message Format Edge Cases

**Priority**: 🟢 MEDIUM

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Empty message body | Publish message with `body: ""` | Should reject (no data to deserialize) | ✅ Validation error |
| Body is array instead of object | `body: [1,2,3]` instead of `{...}` | Should reject with clear error | ✅ Type error |
| Body contains only null values | `{orderId: null, totalAmount: null}` | Should reject required fields | ✅ Validation error for required properties |
| Extremely long string values | Property with 10MB string value | Should enforce string length limits | ✅ Length validation |
| Special numeric values | `NaN`, `Infinity`, `-0` in floats | Should reject or normalize | ✅ Valid numeric handling |
| Large precision decimals | Very precise float values (15+ decimal places) | Should handle or round appropriately | ✅ Precision preserved OR documented rounding |

**Implementation Notes**:
- Create edge case fixtures
- Test serializer directly with boundary values
- Document numeric precision handling

---

### 5. Custom Configuration & Routing

**Focus**: Testing attribute overrides and custom routing strategies

#### 5.1 AMQP Routing Attributes

**Priority**: 🟢 MEDIUM

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| #[MessengerTransport('custom')] override | Event with custom exchange attribute | Should publish to specified exchange | ✅ Message in custom exchange<br/>✅ AmqpStamp reflects custom exchange |
| #[AmqpRoutingKey('custom.key')] override | Event with custom routing key attribute | Should use specified routing key | ✅ Message routing key matches<br/>✅ Correct queue receives message |
| Event without #[MessageName] attribute | Outbox event missing required attribute | Should throw clear error on publish | ✅ RuntimeException<br/>✅ Error identifies missing attribute |
| Multiple routing attributes | Event with both exchange and routing key overrides | Both should be applied | ✅ Custom exchange used<br/>✅ Custom routing key used |
| Invalid exchange name | #[MessengerTransport('non.existent')] | Should fail with clear error | ✅ AMQP error<br/>✅ Exchange not found message |

**Implementation Notes**:
- Create test events with routing attributes
- Verify AmqpStamp contents
- Test against real RabbitMQ exchanges

---

#### 5.2 Custom Routing Strategy

**Priority**: 🟢 LOW

| Scenario | Test Case | Expected Behavior | Assertions |
|----------|-----------|-------------------|------------|
| Custom AmqpRoutingStrategyInterface implementation | Register custom strategy in services.yaml | Should use custom logic for routing | ✅ Custom strategy invoked<br/>✅ Headers/routing key from custom logic |
| Routing strategy with additional headers | Custom strategy adds X-Custom-Header | Headers should appear in AMQP message | ✅ Custom headers in message |
| Dynamic routing based on event content | Route based on event properties (e.g., region) | Messages routed to different exchanges | ✅ Events routed correctly by content |

**Implementation Notes**:
- Create custom routing strategy fixture
- Override service definition in test configuration
- Verify with AMQP header inspection

---

## Implementation Strategy

### Phase 1: Critical Data Integrity (Priority 🔴)
**Est. 5-8 test files, 15-20 test methods**

Focus: Handler exceptions, deduplication edge cases, concurrent processing
- Start with transactional rollback scenarios
- Add concurrent worker tests
- Implement deduplication race conditions

### Phase 2: Error Handling & Recovery (Priority 🟡)
**Est. 3-5 test files, 10-15 test methods**

Focus: Serialization errors, connection failures, failed message recovery
- Test malformed message handling
- Simulate infrastructure failures
- Verify failed transport workflows

### Phase 3: Validation & Security (Priority 🟡)
**Est. 2-3 test files, 10-12 test methods**

Focus: Input validation, injection prevention
- Test malicious payload handling
- Verify parameterized queries
- Edge case validation

### Phase 4: Advanced Scenarios (Priority 🟢)
**Est. 2-4 test files, 8-10 test methods**

Focus: Resource limits, custom routing, edge cases
- Performance/stress testing
- Custom configuration testing
- Boundary value testing

---

## Test Infrastructure Enhancements Needed

### New Test Fixtures
- `ThrowingHandler` - Handler that throws exceptions
- `SlowHandler` - Handler with artificial delay
- `DatabaseConstraintHandler` - Handler that violates constraints
- `MaliciousPayloadEvent` - Event with injection attempts
- `CustomRoutingEvent` - Event with routing attributes
- `LargePayloadEvent` - Event with oversized data

### Helper Methods to Add
- `spawnParallelWorkers(count, transport, limit)` - Run multiple workers concurrently
- `killWorkerDuringProcessing(signal)` - Simulate worker crash
- `simulateNetworkPartition()` - Docker network manipulation
- `createMalformedAmqpMessage(queue, defect)` - Manually craft broken messages
- `assertMessageInFailedTransport(expectedError)` - Verify failed transport contents

### Configuration Additions
- Test scenarios for `retry_strategy` variations
- `time_limit` and `memory_limit` configurations
- Custom `AmqpRoutingStrategy` service definitions

---

## Success Metrics

**Coverage Goals**:
- 🎯 Handler exception scenarios: 100% covered
- 🎯 Deduplication edge cases: 100% covered
- 🎯 Concurrent processing: 100% covered
- 🎯 Serialization errors: 90%+ covered
- 🎯 Connection failures: 80%+ covered
- 🎯 Validation/security: 90%+ covered

**Quality Goals**:
- All tests run in <30 seconds total
- No flaky tests (deterministic outcomes)
- Clear test names following pattern: `test[Scenario][Condition][ExpectedBehavior]`
- Each test isolated (no interdependencies)

---

## Next Steps

1. **Review this document** - Prioritize scenarios based on production risk
2. **Create task breakdown** - Convert each priority 🔴 category into a separate implementation task
3. **Start with Phase 1** - Begin with critical data integrity tests
4. **Iterate** - Implement, run, adjust, repeat for each phase

---

## Notes

- Some scenarios may overlap (e.g., concurrent + deduplication)
- Performance/stress tests may belong in separate test suite (integration vs functional)
- Security scenarios assume standard Doctrine DBAL protections (parameterized queries)
- Infrastructure failure tests may require Docker manipulation capabilities

---

**Document Status**: 📝 DRAFT - Ready for review and task breakdown
