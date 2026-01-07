import { Link } from "react-router-dom";   // ← NUEVA LÍNEA
import BLogo from "../../assets/BLogo.webp";
import {
  FiHome,
  FiList,
} from "react-icons/fi";
import "./Home.css";
import PageTransition from "../../components/page-transition";

function Home() {
  const items = [
    { icon: <FiList size={48} />, title: "Panel de facturación electronica", path: "/admin" },
    { icon: <FiHome size={48} />, title: "Facturación electronica", path: "/facturacion" },
  ];

  return (
    <PageTransition>
      <div className="page-wrapper">
        <section className="start">
          <img src={BLogo} alt="Logo" />
          <h1>Bienvenido al Portal de Packvisión® SAS</h1>
        </section>

        <section className="containers">
          {items.map((item, index) => (
            <Link
              key={index}
              to={item.path}
              className="container-item"
              style={{ textDecoration: "none" }}
            >
              <div className="icon-wrapper">{item.icon}</div>
              <p className="item-title">{item.title}</p>
            </Link>
          ))}
        </section>

        <footer className="footer">
          <div className="footer-line"></div>

          <div className="footer-content">
            <p className="footer-text">
              Packvisión® SAS 2025. Todos los derechos reservados
            </p>

            <span className="footer-version">
              v1.0.1
            </span>
          </div>
        </footer>
      </div>
    </PageTransition>
  );
}


export default Home;