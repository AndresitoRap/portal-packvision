import { motion } from "framer-motion";

export default function PageTransition({ children }: { children: React.ReactNode }) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 40 }}   // Arranca abajo y opaco
      animate={{ opacity: 1, y: 0 }}    // Sube suave
      exit={{ opacity: 0, y: 40 }}      // Sale hacia abajo
      transition={{ duration: 0.35, ease: "easeOut" }} // Animación suave
      style={{ height: "100%" }}
    >
      {children}
    </motion.div>
  );
}
