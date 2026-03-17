<!DOCTYPE html>
<html>
<head>
    <title>Student Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #1e2634 0%, #427a9f 100%);
        }
        .hover-gradient {
            transition: all 0.3s ease;
        }
        .hover-gradient:hover {
            background: linear-gradient(135deg, #2665ec 0%, #9c2dd1 100%);
        }
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .status-pending {
            background-color: #fcb71220;
            color: #fcb712;
        }
        .status-completed {
            background-color: #40d75720;
            color: #40d757;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2665ec 0%, #9c2dd1 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #9c2dd1 0%, #2665ec 100%);
            transform: scale(1.05);
        }
    </style>
</head>