<?php
error_reporting(0);
ini_set('display_errors', 0);

$tasks = $tasks ?? [];
?>

<div class="container mx-auto">

    <!-- HEADER -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-[#1e2634] tracking-tight">
            My Tasks
        </h2>
        
        <!-- FILTER -->
        <div class="flex space-x-2">

            <button class="px-4 py-2 bg-white border border-[#e8eef5] rounded-xl text-[#427a9f] hover:bg-[#2665ec] hover:text-white transition">
                All
            </button>

            <button class="px-4 py-2 bg-white border border-[#e8eef5] rounded-xl text-[#427a9f] hover:bg-[#fcb712] hover:text-white transition">
                Pending
            </button>

            <button class="px-4 py-2 bg-white border border-[#e8eef5] rounded-xl text-[#427a9f] hover:bg-[#40d757] hover:text-white transition">
                Completed
            </button>

        </div>
    </div>

    <!-- TASK GRID -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <?php if (!empty($tasks) && is_array($tasks)): ?>
        <?php foreach($tasks as $task): ?>

        <div class="bg-white rounded-2xl shadow-sm border border-[#e8eef5] hover:shadow-md transition min-h-[220px]">

            <div class="p-6">

                <!-- TITLE + PRIORITY -->
                <div class="flex justify-between items-start mb-3">

                    <h3 class="text-lg font-semibold text-[#1e2634]">
                        <?php echo $task['title'] ?? '-'; ?>
                    </h3>

                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                    <?php 
                    if(($task['priority'] ?? '') == 'high') echo 'bg-[#fc343d]/10 text-[#fc343d]';
                    elseif(($task['priority'] ?? '') == 'medium') echo 'bg-[#fcb712]/10 text-[#fcb712]';
                    else echo 'bg-[#40d757]/10 text-[#40d757]';
                    ?>">
                        <?php echo ucfirst($task['priority'] ?? 'low'); ?>
                    </span>

                </div>
                
                <!-- DESCRIPTION -->
                <p class="text-[#427a9f] text-sm mb-4">
                    <?php echo $task['description'] ?? '-'; ?>
                </p>
                
                <!-- DEADLINE -->
                <div class="flex items-center text-sm text-[#427a9f] mb-4">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7V3m8 4V3m-9 8h10"></path>
                    </svg>
                    <?php echo $task['deadline'] ?? '-'; ?>
                </div>

                <!-- STATUS + ACTION -->
                <div class="flex items-center justify-between">

                    <!-- STATUS -->
                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                    <?php echo ($task['status'] ?? '') == 'pending'
                    ? 'bg-[#fcb712]/10 text-[#fcb712]'
                    : 'bg-[#40d757]/10 text-[#40d757]'; ?>">

                        <?php echo ucfirst($task['status'] ?? 'pending'); ?>
                    </span>

                    <!-- ACTION -->
                    <?php if(($task['status'] ?? '') == 'pending'): ?>

                        <a href="submit_task.php?id=<?php echo $task['id'] ?? 0; ?>"
                           class="px-4 py-2 text-sm rounded-xl text-white bg-[#2665ec] hover:opacity-90 transition">
                            Submit
                        </a>

                    <?php else: ?>

                        <a href="#"
                           class="text-[#2665ec] hover:opacity-80 transition font-medium text-sm">
                            View
                        </a>

                    <?php endif; ?>

                </div>

            </div>
        </div>

        <?php endforeach; ?>

        <?php else: ?>

        <!-- EMPTY STATE -->
        <div class="col-span-2 text-center py-10 text-[#427a9f]">
            No tasks available
        </div>

        <?php endif; ?>

    </div>

    <!-- ================= STATS ================= -->
    <div class="mt-8 bg-white rounded-2xl shadow-sm border border-[#e8eef5] p-6">

        <h3 class="text-lg font-semibold text-[#1e2634] mb-6">
            Task Statistics
        </h3>

        <div class="grid grid-cols-3 gap-4 text-center">

            <div>
                <div class="text-3xl font-bold text-[#2665ec]">
                    <?php echo count($tasks); ?>
                </div>
                <div class="text-sm text-[#427a9f]">Total</div>
            </div>

            <div>
                <div class="text-3xl font-bold text-[#fcb712]">
                    <?php echo count(array_filter($tasks, fn($t) => $t['status']=='pending')); ?>
                </div>
                <div class="text-sm text-[#427a9f]">Pending</div>
            </div>

            <div>
                <div class="text-3xl font-bold text-[#40d757]">
                    <?php echo count(array_filter($tasks, fn($t) => $t['status']=='completed')); ?>
                </div>
                <div class="text-sm text-[#427a9f]">Completed</div>
            </div>

        </div>

    </div>

</div>