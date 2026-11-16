<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Exams, exam paper workflow, syllabus, homework/worksheets, notices,
 * parent messages (WhatsApp/SMS outbox) and expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('academic_session_id');
            $table->string('name');                 // First Term, Mid Term, Final
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->enum('status', ['draft', 'marks_entry', 'published'])->default('draft');
        });

        // One paper of an exam: a subject for a class, with its marks scheme.
        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('exam_id')->index();
            $table->uuid('class_id')->index();
            $table->uuid('subject_id');
            $table->unsignedSmallInteger('max_marks')->default(100);
            $table->unsignedSmallInteger('pass_marks')->default(33);
            $table->date('paper_date')->nullable();
            $table->unique(['exam_id', 'class_id', 'subject_id']);
        });

        Schema::create('marks', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('exam_subject_id')->index();
            $table->uuid('student_id')->index();
            $table->decimal('obtained', 6, 2)->nullable();
            $table->boolean('absent')->default(false);
            $table->unique(['exam_subject_id', 'student_id']);
        });

        /*
         * Paperless question papers: teacher writes, examiner reviews, principal approves.
         * Status moves draft -> submitted -> reviewed -> approved (or returned to the teacher).
         */
        Schema::create('exam_papers', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('exam_id')->nullable()->index();
            $table->uuid('class_id');
            $table->uuid('subject_id');
            $table->string('title');
            $table->longText('content')->nullable();
            $table->unsignedSmallInteger('total_marks')->default(100);
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->enum('status', ['draft', 'submitted', 'reviewed', 'approved', 'returned'])->default('draft');
            $table->uuid('author_id')->nullable();      // users.id
            $table->text('review_note')->nullable();
        });

        Schema::create('syllabus_topics', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('class_id')->index();
            $table->uuid('subject_id');
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending');
            $table->date('completed_on')->nullable();
        });

        // Daily diary, homework and worksheets pushed to parents.
        Schema::create('homework', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('section_id')->index();
            $table->uuid('subject_id')->nullable();
            $table->enum('kind', ['homework', 'worksheet', 'diary']);
            $table->string('title');
            $table->text('body')->nullable();
            $table->date('assigned_on');
            $table->date('due_on')->nullable();
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->syncable();
            $table->string('title');
            $table->text('body');
            $table->enum('audience', ['all', 'parents', 'staff'])->default('all');
            $table->date('published_on');
        });

        // Outbox for WhatsApp / SMS. Devices queue rows; the server job sends them.
        Schema::create('messages', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('family_id')->nullable()->index();
            $table->string('to_name')->nullable();
            $table->string('to_phone');
            $table->enum('channel', ['whatsapp', 'sms'])->default('whatsapp');
            $table->enum('kind', ['late_fee', 'fee_voucher', 'absent', 'attendance_report', 'result', 'homework', 'general']);
            $table->text('body');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->string('error')->nullable();
            $table->index(['school_id', 'status']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->syncable();
            $table->string('category');
            $table->string('description')->nullable();
            $table->unsignedInteger('amount');
            $table->date('spent_on');
            $table->string('paid_to')->nullable();
        });

        // Basic salary kept on the staff row for simple schools; structures refine it.
        Schema::table('staff', function (Blueprint $table) {
            $table->unsignedInteger('basic_salary')->default(0);
            $table->unsignedInteger('allowances')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn(['basic_salary', 'allowances']));
        foreach (['expenses', 'messages', 'notices', 'homework', 'syllabus_topics', 'exam_papers', 'marks',
            'exam_subjects', 'exams'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
