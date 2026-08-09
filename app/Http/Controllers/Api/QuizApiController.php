<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Quiz;
use App\Models\Result;
use App\Models\ResultDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class QuizApiController extends Controller
{
    /**
     * Join a quiz room
     */
    public function join(Request $request)
    {
        $request->validate([
            'room_name' => 'required|string',
            'student_id' => 'required|string',
        ]);

        $roomName = $request->room_name;
        $studentId = $request->student_id;

        // 1. Find the teacher (User) by room_name
        $teacher = User::where('room_name', $roomName)->first();

        if (!$teacher) {
            return response()->json(['error' => 'Room not found.'], 404);
        }

        // 2. Find the active quiz for this teacher.
        // Assuming duration is in minutes. We check if now is between start_datetime and start_datetime + duration.
        $now = Carbon::now();
        
        $activeQuiz = Quiz::where('userid', $teacher->id)
            ->where('start_datetime', '<=', $now)
            ->whereRaw('DATE_ADD(start_datetime, INTERVAL duration MINUTE) >= ?', [$now])
            ->orderBy('start_datetime', 'desc')
            ->with(['questions' => function ($query) {
                // Select only fields we want to send to the student (exclude right_option)
                $query->select('id', 'quiz_id', 'text', 'image', 'option1', 'option2', 'option3', 'option4');
            }])
            ->first();

        // Fallback: just get the latest quiz if no strictly active one is found
        if (!$activeQuiz) {
            $activeQuiz = Quiz::where('userid', $teacher->id)
                ->orderBy('start_datetime', 'desc')
                ->with(['questions' => function ($query) {
                    $query->select('id', 'quiz_id', 'text', 'image', 'option1', 'option2', 'option3', 'option4');
                }])
                ->first();
        }

        if (!$activeQuiz) {
            return response()->json(['error' => 'No active quiz found for this room.'], 404);
        }

        // 3. Return the payload securely
        return response()->json([
            'student_id' => $studentId,
            'quiz' => [
                'id' => $activeQuiz->id,
                'title' => $activeQuiz->title,
                'duration' => $activeQuiz->duration,
                'start_datetime' => $activeQuiz->start_datetime,
            ],
            'questions' => $activeQuiz->questions
        ]);
    }

    /**
     * Submit quiz answers
     */
    public function submit(Request $request)
    {
        $request->validate([
            'quiz_id' => 'required|integer|exists:quizzes,id',
            'student_id' => 'required|string',
            'answers' => 'required|array'
        ]);

        $quizId = $request->quiz_id;
        $studentId = $request->student_id;
        $answers = $request->answers;

        // 1. Check for duplicate submission (Idempotency)
        $existingResult = Result::where('quiz_id', $quizId)
            ->where('student_id', $studentId)
            ->first();

        if ($existingResult) {
            $quiz = Quiz::withCount('questions')->findOrFail($quizId);
            return response()->json([
                'message' => 'Quiz already submitted.',
                'score' => $existingResult->score,
                'total' => $quiz->questions_count
            ], 200);
        }

        $quiz = Quiz::with('questions')->findOrFail($quizId);
        
        // 2. Grade the submission
        $score = 0;
        $correctAnswers = [];
        
        foreach ($quiz->questions as $question) {
            $correctAnswers[$question->id] = $question;
        }

        $resultDetails = [];

        foreach ($answers as $answer) {
            $questionId = $answer['questionId'] ?? null;
            $selectedOptionIndex = $answer['selectedOption'] ?? null; // e.g., 1, 2, 3, 4
            
            if (!$questionId || !$selectedOptionIndex) continue;

            $question = $correctAnswers[$questionId] ?? null;
            
            if ($question) {
                // right_option is stored as an integer (1, 2, 3, or 4) in the DB
                // so we just compare the selected index directly.
                if ((int)$selectedOptionIndex === (int)$question->right_option) {
                    $score++; // 1 point per question
                }
                
                $resultDetails[] = [
                    'question_id' => $questionId,
                    'selected_option' => $selectedOptionIndex,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }
        }

        // 3. Store result inside a transaction
        DB::beginTransaction();
        try {
            $result = Result::create([
                'student_id' => $studentId,
                'quiz_id' => $quizId,
                'score' => $score
            ]);

            // Add result_id to details
            foreach ($resultDetails as &$detail) {
                $detail['result_id'] = $result->id;
            }
            
            ResultDetail::insert($resultDetails);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Quiz submitted successfully!',
                'score' => $score,
                'total' => count($quiz->questions)
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to save submission. Please try again.'], 500);
        }
    }
}
