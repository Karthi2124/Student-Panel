<?php
$current_page = $_GET['page'] ?? 'dashboard';
?>

<div class="flex h-screen bg-[#f3f7f8]">
    <!-- SIDEBAR -->
    <div class="w-64 text-white flex flex-col shadow-2xl"
         style="background: linear-gradient(180deg, #0f172a 0%, #1e2634 100%);">

        <!-- LOGO -->
        <div class="p-6 text-xl font-bold border-b border-[#2c3a4f]">
            <span class="text-white">STUDENT PORTAL</span>
        </div>

        <!-- MENU -->
        <nav class="flex-1 p-4 space-y-2">
            <a href="index.php?page=dashboard" 
               class="block p-3 rounded-xl transition-all duration-300
               <?php echo $current_page == 'dashboard'
               ? 'bg-[#2665ec] shadow-lg shadow-blue-500/30 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">
                <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
            </a>

            <a href="index.php?page=lab_assessment" 
               class="block p-3 rounded-xl transition-all duration-300
               <?php echo $current_page == 'lab_assessment'
               ? 'bg-[#2665ec] shadow-xl shadow-blue-500/40 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">
                <i class="fas fa-flask mr-3"></i> Lab Assessment
            </a>

            <a href="index.php?page=programming_platform" 
               class="block p-3 rounded-xl transition-all duration-300
               <?php echo $current_page == 'programming_platform'
               ? 'bg-[#2665ec] shadow-xl shadow-blue-500/40 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">
                <i class="fas fa-code mr-3"></i> Programming Platform
            </a>

            <a href="index.php?page=tasks" 
               class="block p-3 rounded-xl transition-all duration-300
               <?php echo $current_page == 'tasks'
               ? 'bg-[#2665ec] shadow-xl shadow-blue-500/40 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">
                <i class="fas fa-tasks mr-3"></i> Tasks
            </a>

            <a href="#" 
               class="block p-3 rounded-xl text-gray-300 transition-all duration-300 hover:bg-[#1e293b]">
                <i class="fas fa-chart-bar mr-3"></i> Reports
            </a>

            <a href="#" 
               class="block p-3 rounded-xl text-gray-300 transition-all duration-300 hover:bg-[#1e293b]">
                <i class="fas fa-cog mr-3"></i> Settings
            </a>
        </nav>

        <!-- FOOTER -->
        <div class="p-4 border-t border-[#2c3a4f]">
            <a href="logout.php"
               class="text-[#fc343d] hover:text-red-400 transition-colors duration-300">
                <i class="fas fa-sign-out-alt mr-2"></i> Sign Out
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="flex-1 flex flex-col">
        <!-- HEADER -->
        <div class="bg-white shadow-sm p-4 flex justify-between items-center border-b border-[#e5eaf0]">
            <h1 class="text-xl font-semibold text-[#1e2634]">
                <?php echo ucfirst(str_replace('_', ' ', $current_page)); ?>
            </h1>
            <div class="flex items-center space-x-4">
                <span class="text-[#427a9f] text-sm">
                    <i class="far fa-user mr-2"></i> Welcome, Student
                </span>
            </div>
        </div>

        <!-- CONTENT AREA -->
        <div class="p-6 overflow-y-auto bg-[#f3f7f8]">