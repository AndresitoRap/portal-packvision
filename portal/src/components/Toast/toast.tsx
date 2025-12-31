import "./Toast.css";

interface ToastProps {
  message: string;
  type?: "success" | "error";
  show: boolean;
}

export default function Toast({ message, type = "success", show }: ToastProps) {
  if (!show) return null;

  return (
    <div className={`toast toast-${type}`}>
      {message}
    </div>
  );
}
