<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Students, families, classes, attendance.
 * All rows use ->syncable(): client UUID, school_id, branch_id, version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('name');                 // 2026-27
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->enum('status', ['planned', 'open', 'closed'])->default('planned');
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->syncable();
            $table->string('name');                 // Nursery, 1, 2 ...
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('active')->default(true);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('class_id')->index();
            $table->uuid('academic_session_id')->index();
            $table->string('name');                 // A, B, Blue
            $table->uuid('class_teacher_id')->nullable(); // staff
            $table->unsignedSmallInteger('capacity')->nullable();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('name');
            $table->string('code', 16)->nullable();
        });

        Schema::create('section_subjects', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('section_id')->index();
            $table->uuid('subject_id');
            $table->uuid('teacher_id')->nullable()->index(); // staff
            $table->unique(['section_id', 'subject_id']);
        });

        // A family groups siblings; the ledger account for fees is per family.
        Schema::create('families', function (Blueprint $table) {
            $table->syncable();
            $table->string('code');                 // FAM-001
            $table->string('primary_guardian_name');
            $table->string('primary_guardian_cnic', 15)->nullable();
            $table->string('primary_phone');
            $table->string('whatsapp_phone')->nullable();
            $table->text('address')->nullable();
            $table->unique(['school_id', 'code']);
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('family_id')->index();
            $table->string('name');
            $table->enum('relation', ['father', 'mother', 'guardian', 'other']);
            $table->string('phone')->nullable();
            $table->string('cnic', 15)->nullable();
            $table->string('occupation')->nullable();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('family_id')->index();
            $table->string('admission_no');
            $table->string('name');
            $table->string('b_form', 15)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female']);
            $table->string('photo_path')->nullable();
            $table->date('admission_date');
            $table->enum('status', ['active', 'left', 'struck_off', 'graduated'])->default('active');
            $table->date('left_on')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['school_id', 'admission_no']);
        });

        // Student in a section for one academic session (history kept per year).
        Schema::create('enrollments', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('student_id')->index();
            $table->uuid('academic_session_id');
            $table->uuid('section_id')->index();
            $table->string('roll_no');
            $table->enum('status', ['active', 'promoted', 'detained', 'left'])->default('active');
            $table->unique(['student_id', 'academic_session_id']);
        });

        /*
         * Student attendance: one row per student per day, mergeable (D2).
         * Corrections bump version; the audit log keeps the history.
         */
        Schema::create('student_attendance', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('student_id');
            $table->uuid('section_id')->index();
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'leave', 'late']);
            $table->string('remark')->nullable();
            $table->unique(['student_id', 'date']);
            $table->index(['section_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['student_attendance', 'enrollments', 'students', 'guardians', 'families',
            'section_subjects', 'subjects', 'sections', 'classes', 'academic_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
