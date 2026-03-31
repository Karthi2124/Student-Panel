<?php
$current_page = $_GET['page'] ?? 'dashboard';
$isProgramming = $current_page == 'programming_platform';
?>

<div class="flex h-screen bg-[#f3f7f8]">

    <!-- SIDEBAR -->
    <div class="<?php echo $isProgramming ? 'w-20' : 'w-64'; ?> text-white flex flex-col shadow-2xl transition-all duration-300"
         style="background: linear-gradient(180deg, #0f172a 0%, #1e2634 100%);">

        <!-- LOGO -->
        <div class="p-6 text-xl font-bold border-b border-[#2c3a4f] flex justify-center items-center">
            <?php if (!$isProgramming): ?>
                <span class="text-white">STUDENT PORTAL</span>
            <?php else: ?>
                <i class="fas fa-graduation-cap text-2xl"></i>
            <?php endif; ?>
        </div>

        <!-- MENU -->
        <nav class="flex-1 p-4 space-y-2 <?php echo $isProgramming ? 'flex flex-col items-center' : ''; ?>">

            <!-- Dashboard -->
            <a href="index.php?page=dashboard" 
               class="block p-3 rounded-xl flex items-center <?php echo $isProgramming ? 'justify-center' : ''; ?>
               transition-all duration-300
               <?php echo $current_page == 'dashboard'
               ? 'bg-[#2665ec] shadow-lg shadow-blue-500/30 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">

                <i class="fas fa-tachometer-alt <?php echo $isProgramming ? '' : 'mr-3'; ?>"></i>

                <?php if (!$isProgramming): ?> Dashboard <?php endif; ?>
            </a>

            <!-- Lab -->
            <a href="index.php?page=lab_assessment" 
               class="block p-3 rounded-xl flex items-center <?php echo $isProgramming ? 'justify-center' : ''; ?>
               transition-all duration-300
               <?php echo $current_page == 'lab_assessment'
               ? 'bg-[#2665ec] shadow-xl shadow-blue-500/40 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">

                <i class="fas fa-flask <?php echo $isProgramming ? '' : 'mr-3'; ?>"></i>

                <?php if (!$isProgramming): ?> Lab Assessment <?php endif; ?>
            </a>

            <!-- Programming -->
            <a href="index.php?page=programming_platform" 
               class="block p-3 rounded-xl flex items-center <?php echo $isProgramming ? 'justify-center' : ''; ?>
               transition-all duration-300
               <?php echo $current_page == 'programming_platform'
               ? 'bg-[#2665ec] shadow-xl shadow-blue-500/40 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">

                <i class="fas fa-code <?php echo $isProgramming ? '' : 'mr-3'; ?>"></i>

                <?php if (!$isProgramming): ?> Programming Platform <?php endif; ?>
            </a>

            <!-- Tasks -->
            <a href="index.php?page=tasks" 
               class="block p-3 rounded-xl flex items-center <?php echo $isProgramming ? 'justify-center' : ''; ?>
               transition-all duration-300
               <?php echo $current_page == 'tasks'
               ? 'bg-[#2665ec] shadow-xl shadow-blue-500/40 text-white'
               : 'text-gray-300 hover:bg-[#1e293b]'; ?>">

                <i class="fas fa-tasks <?php echo $isProgramming ? '' : 'mr-3'; ?>"></i>

                <?php if (!$isProgramming): ?> Tasks <?php endif; ?>
            </a>

            <!-- Reports -->
            <a href="#" 
               class="block p-3 rounded-xl flex items-center <?php echo $isProgramming ? 'justify-center' : ''; ?>
               text-gray-300 transition-all duration-300 hover:bg-[#1e293b]">

                <i class="fas fa-chart-bar <?php echo $isProgramming ? '' : 'mr-3'; ?>"></i>

                <?php if (!$isProgramming): ?> Reports <?php endif; ?>
            </a>

            <!-- Settings -->
            <a href="#" 
               class="block p-3 rounded-xl flex items-center <?php echo $isProgramming ? 'justify-center' : ''; ?>
               text-gray-300 transition-all duration-300 hover:bg-[#1e293b]">

                <i class="fas fa-cog <?php echo $isProgramming ? '' : 'mr-3'; ?>"></i>

                <?php if (!$isProgramming): ?> Settings <?php endif; ?>
            </a>
        </nav>

        <!-- FOOTER -->
        <div class="p-4 border-t border-[#2c3a4f] flex justify-center">
            <a href="logout.php"
               class="text-[#fc343d] hover:text-red-400 transition-colors duration-300 flex items-center">

                <i class="fas fa-sign-out-alt <?php echo $isProgramming ? '' : 'mr-2'; ?>"></i>

                <?php if (!$isProgramming): ?> Sign Out <?php endif; ?>
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