<?php

/*
 * Copyright 2017 MongoDB, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace MongoDB\Operation\Options;

final class FindOptions
{
    const ALLOW_PARTIAL_RESULTS = 'allowPartialResults';
    const BATCH_SIZE = 'batchSize';
    const COLLATION = 'collation';
    const COMMENT = 'comment';
    const CURSOR_TYPE = 'cursorType';
    const HINT = 'hint';
    const LIMIT = 'limit';
    const MAX = 'max';
    const MAX_AWAIT_TIME_MS = 'maxAwaitTimeMS';
    const MAX_SCAN = 'maxScan';
    const MAX_TIME_MS = 'maxTimeMS';
    const MIN = 'min';
    const MODIFIERS = 'modifiers';
    const NO_CURSOR_TIMEOUT = 'noCursorTimeout';
    const OPLOG_REPLAY = 'oplogReplay';
    const PROJECTION = 'projection';
    const READ_CONCERN = 'readConcern';
    const READ_PREFERENCE = 'readPreference';
    const RETURN_KEY = 'returnKey';
    const SESSION = 'session';
    const SHOW_RECORD_ID = 'showRecordId';
    const SKIP = 'skip';
    const SNAPSHOT = 'snapshot';
    const SORT = 'sort';
    const TYPE_MAP = 'typeMap';
}
