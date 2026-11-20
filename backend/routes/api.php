<?php

use App\Http\Controllers\ApplicationIntakeController;
use App\Http\Controllers\CallAnswerController;
use App\Http\Controllers\CallContextController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\QuestionSetController;
use App\Http\Controllers\ResultReconciliationController;
use App\Http\Controllers\VobizCallController;
use App\Http\Middleware\AuthenticateCareerApi;
use App\Http\Middleware\AuthenticateOperations;
use Illuminate\Support\Facades\Route;

// Submodules: application-intake (INTAKE-01), call-dispatch (DISPATCH-01), conversation (CONVERSATION-01), answers (ANSWERS-01), result-delivery (DELIVERY-01), operations (OPERATIONS-01).
Route::prefix('v1')->middleware([AuthenticateCareerApi::class, 'throttle:60,1'])->group(function () {
    Route::post('/applications', [ApplicationIntakeController::class, 'store']);
    Route::post('/question-sets', [QuestionSetController::class, 'store']);
    Route::get('/calls/{attempt}/answers', [CallAnswerController::class, 'show']);
    Route::get('/calls/{attempt}/transcript', [ResultReconciliationController::class, 'transcript']);
    Route::get('/applications/{applicationId}/calls', [ResultReconciliationController::class, 'calls']);
});

// Submodule: operations (OPERATIONS-01). Separate token protects operational reads and mutations.
Route::prefix('v1/operations')->middleware([AuthenticateOperations::class, 'throttle:30,1'])->group(function () {
    Route::get('/calls', [OperationsController::class, 'calls']);
    Route::get('/deliveries', [OperationsController::class, 'deliveries']);
    Route::post('/deliveries/{delivery}/retry', [OperationsController::class, 'retryDelivery']);
    Route::post('/calls/{attempt}/retry-extraction', [OperationsController::class, 'retryExtraction']);
    Route::get('/question-sets', [OperationsController::class, 'questionSets']);
    Route::post('/question-sets', [OperationsController::class, 'publishQuestionSet']);
    Route::get('/audit', [OperationsController::class, 'audit']);
});

// Submodule: call-dispatch (DISPATCH-01). Vobiz callbacks authenticate with a separate callback token.
Route::post('/vobiz/answer', [VobizCallController::class, 'answer']);
Route::post('/vobiz/hangup', [VobizCallController::class, 'hangup']);

// Submodule: conversation (CONVERSATION-01). Bridge token is checked inside the controller.
Route::get('/vobiz/internal/attempts/{attempt}', [CallContextController::class, 'show']);
Route::post('/vobiz/internal/attempts/{attempt}/messages', [CallContextController::class, 'message']);
Route::post('/vobiz/internal/attempts/{attempt}/outcome', [CallContextController::class, 'outcome']);
Route::post('/vobiz/internal/attempts/{attempt}/usage', [CallContextController::class, 'usage']);
Route::post('/vobiz/internal/attempts/{attempt}/callback', [CallContextController::class, 'callback']);
